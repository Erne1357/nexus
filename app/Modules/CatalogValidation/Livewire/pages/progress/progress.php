<?php

use App\Modules\CatalogValidation\Actions\OpenCatalogValidation;
use App\Modules\CatalogValidation\Actions\RemindPendingReviews;
use App\Modules\CatalogValidation\Actions\ResolveFlag;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\CatalogValidationPermission;
use App\Modules\CatalogValidation\Jobs\RemindPendingReviewsJob;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\CatalogValidation\Queries\ReviewOwner;
use App\Modules\CatalogValidation\Queries\ValidationProgressQuery;
use App\Modules\CatalogValidation\Queries\ValidationWindowQuery;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\StageStatus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\StageWindow;
use App\Modules\Facilities\Queries\RoomQuery;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * Avance de validación (tarea 2.5; docs/dominio/reglas-de-negocio.md, "Validación de catálogos"), variante A
 * «matriz» (DESIGN.md): el avance global del periodo activo, la ventana de la etapa de validación de catálogos con su
 * fecha límite (la ventana general, CV11) y una fila por departamento activo con el estado de cada catálogo, de las
 * observaciones y de su carga, y otra de Servicios Escolares con las materias, los planes y las carreras de todo el Tec
 * (CV15); sin jefe de Servicios Escolares vigente, el drawer «Abrir validación» no deja abrir esos catálogos y dice
 * por qué. La validación es de cada departamento para todo el Tec: la pantalla no filtra por campus
 * (14 de noviembre de 2026). Lo ven y recuerdan Subdirección y el admin (CatalogReviewPolicy::viewProgress y remind,
 * supuesto por confirmar); cada método público vuelve a autorizar, porque la petición puede llegar con una pantalla
 * que abrió otra sesión. «Observaciones abiertas» junta las de todos los departamentos (CV10); resolver una, con la nota
 * de lo que se hizo, desde ese drawer o el de un departamento sigue a CatalogReviewPolicy::resolveFlag sobre su revisión.
 */
new #[Title('Avance de validación')] class extends Component
{
    use Toast;

    #[Session]
    public string $search = '';

    /** '' (todos), 'pending' (con algo sin validar) o 'validated' (todo validado). */
    #[Session]
    public string $status = '';

    public bool $showDrawer = false;

    /** El drawer «Abrir validación» (CV9) y los catálogos que se marcaron para abrir. */
    public bool $showOpen = false;

    /** El drawer «Observaciones abiertas» de todos los departamentos (CV10). */
    public bool $showFlags = false;

    /** @var list<string> */
    public array $toOpen = [];

    /** El departamento del drawer; el navegador no lo puede cambiar. */
    #[Locked]
    public ?string $detailId = null;

    public function mount(): void
    {
        Gate::authorize('viewProgress', CatalogReview::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(ValidationProgressQuery $progress, ValidationWindowQuery $windows, CatalogReviewQuery $reviews, RoomQuery $rooms, Actor $context): array
    {
        Gate::authorize('viewProgress', CatalogReview::class);

        $term = $context->activeTerm();
        $open = $term === null ? new Collection : $progress->openCampuses($term);
        $canOpen = Gate::allows('open', CatalogReview::class);

        if ($term === null || $open->isEmpty()) {
            return ['term' => $term, 'open' => $open, 'board' => null, 'window' => null, 'detail' => null, 'canOpen' => $canOpen, 'options' => [], 'allFlags' => null, 'roomsWithoutOwner' => 0];
        }

        $withSchoolServices = $this->seesSchoolServices($context);
        $board = $progress->board($term, $this->search, $this->status, withSchoolServices: $withSchoolServices);

        return [
            'term' => $term,
            'open' => $open,
            'board' => $board,
            'window' => $this->window($windows, $term),
            'detail' => $this->showDrawer && $this->detailId !== null ? $this->detail($progress, $term, $board, $withSchoolServices) : null,
            'canOpen' => $canOpen,
            'options' => $reviews->openingOptions($term),
            'allFlags' => $this->showFlags ? $progress->allOpenFlags($term, $withSchoolServices) : null,
            'roomsWithoutOwner' => $canOpen && $this->showOpen ? $rooms->withoutOwnerCount() : 0,
        ];
    }

    /**
     * Abre el drawer «Abrir validación»: solo el admin (CatalogReviewPolicy::open, CV9).
     */
    public function startOpening(): void
    {
        Gate::authorize('open', CatalogReview::class);

        $this->toOpen = [];
        $this->showOpen = true;
    }

    /**
     * Abre la validación de los catálogos marcados en el periodo activo (OpenCatalogValidation).
     */
    public function openValidation(OpenCatalogValidation $open, CatalogReviewQuery $reviews, Actor $context): void
    {
        Gate::authorize('open', CatalogReview::class);

        $term = $context->activeTerm() ?? abort(404);
        $catalogs = array_values(array_filter(array_map(fn (string $value): ?Catalog => Catalog::tryFrom($value), $this->toOpen)));

        if ($catalogs === []) {
            $this->addError('toOpen', 'Marca al menos un catálogo.');

            return;
        }

        $created = $open->handle($term, $catalogs, $this->user());

        $this->showOpen = false;
        $this->toOpen = [];
        if ($created > 0) {
            // La página se vuelve a pintar entera, con el menú y la franja «Oculta para los demás» al día.
            $this->success('Validación abierta', e($term->name).': se avisó a los jefes.', redirectTo: route('catalog-validation.progress'));

            return;
        }

        $chosen = collect($reviews->openingOptions($term))->filter(fn (array $option): bool => in_array($option['catalog'], $catalogs, true));
        $this->info('No se abrió nada', match (true) {
            $chosen->every(fn (array $option): bool => $option['open']) => 'Esos catálogos ya estaban abiertos.',
            $chosen->contains(fn (array $option): bool => $option['blocked'] !== null) => $chosen->first(fn (array $option): bool => $option['blocked'] !== null)['blocked'],
            default => 'No hay departamentos activos a los que abrirles la validación.',
        });
    }

    /**
     * Abre el drawer de un departamento activo o de Servicios Escolares (CV15).
     */
    public function open(string $id, CatalogReviewQuery $reviews, Actor $context): void
    {
        Gate::authorize('viewProgress', CatalogReview::class);

        $owner = $reviews->activeOwners([$id])->first() ?? abort(404);
        abort_if($owner->isSchoolServices() && ! $this->seesSchoolServices($context), 404);

        $this->detailId = $owner->id;
        $this->showDrawer = true;
    }

    /**
     * Abre el drawer «Observaciones abiertas» con las de todos los departamentos (CV10).
     */
    public function openFlags(): void
    {
        Gate::authorize('viewProgress', CatalogReview::class);

        $this->showFlags = true;
    }

    /**
     * Recuerda a los jefes con pendientes (el recordatorio, CV11: campana y correo). De un departamento o de Servicios
     * Escolares (CV15), en línea; sin ninguno («Recordar a todos los pendientes»), de los de las filas visibles (búsqueda
     * y estado) en un job en cola, porque tarda.
     */
    public function remind(RemindPendingReviews $remind, ValidationProgressQuery $progress, CatalogReviewQuery $reviews, Actor $context, ?string $departmentId = null): void
    {
        Gate::authorize('remind', CatalogReview::class);

        $term = $context->activeTerm() ?? abort(404);

        if ($departmentId === null) {
            $ids = collect($progress->board($term, $this->search, $this->status, withSchoolServices: $this->seesSchoolServices($context))['rows'])
                ->filter(fn (array $row): bool => $row['remind_blocked_reason'] === null)
                ->map(fn (array $row): string => $row['department']->id)
                ->values()
                ->all();

            if ($ids === []) {
                $this->info('Nadie por recordar', 'Ningún departamento visible tiene jefe con catálogos pendientes.');

                return;
            }

            RemindPendingReviewsJob::dispatch($term->getKey(), $this->user()->getKey(), $ids);
            $this->info('Enviando recordatorios a '.count($ids).(count($ids) === 1 ? ' departamento' : ' departamentos'), e($term->name));

            return;
        }

        $department = $reviews->activeOwners([$departmentId])->first() ?? abort(404);
        abort_if($department->isSchoolServices() && ! $this->seesSchoolServices($context), 404);
        $notified = $remind->handle($term, $this->user(), [$department->id]);

        if ($notified === 0) {
            $this->info('Nadie por recordar', 'Ningún jefe tiene catálogos pendientes que recordar.');

            return;
        }

        $this->success($notified === 1 ? 'Se avisó a 1 jefe' : "Se avisó a {$notified} jefes", e($department->name));
    }

    /**
     * Marca como resuelta una observación con la nota opcional de lo que se hizo (CV10), que le llega en la campana al jefe
     * que la puso (NotifyFlagResolved).
     */
    public function resolveFlag(string $id, ValidationProgressQuery $progress, ResolveFlag $resolveFlag, mixed $note = null): void
    {
        if ($note !== null && ! is_string($note)) {
            throw ValidationException::withMessages(['note' => 'La nota tiene que ser texto.']);
        }

        Gate::authorize('viewProgress', CatalogReview::class);

        $flag = $progress->findFlag($id);
        $review = $flag->review;
        Gate::authorize('resolveFlag', $review);

        $resolveFlag->handle($review, $flag, $this->user(), $note);

        $this->success('Observación resuelta', e($review->heading));
    }

    /**
     * La ventana general de la etapa de validación de catálogos y cuánto de ella pasó, si tiene fechas (CV11: su fin es la
     * fecha límite de los avisos).
     *
     * @return array{window: StageWindow, elapsed: int}|null
     */
    private function window(ValidationWindowQuery $windows, Term $term): ?array
    {
        $window = $windows->window($term);

        if ($window === null || $window->startsOn === null) {
            return null;
        }

        $days = (int) $window->startsOn->diffInDays($window->endsOn) + 1;
        $elapsed = match ($window->status) {
            StageStatus::Upcoming => 0,
            StageStatus::Finished => 100,
            default => (int) min(100, round(((int) $window->startsOn->diffInDays(today()->toImmutable()) + 1) / $days * 100)),
        };

        return ['window' => $window, 'elapsed' => $elapsed];
    }

    /**
     * Lo del drawer de un departamento o de Servicios Escolares: su fila, sus observaciones abiertas y su último
     * recordatorio.
     *
     * @param  array<string, mixed>  $board
     * @return array<string, mixed>|null
     */
    private function detail(ValidationProgressQuery $progress, Term $term, array $board, bool $withSchoolServices): ?array
    {
        $row = collect($board['rows'])->first(fn (array $candidate): bool => $candidate['department']->id === $this->detailId)
            ?? ($progress->board($term, ownerId: $this->detailId, withSchoolServices: $withSchoolServices)['rows'][0] ?? null);

        return $row === null ? null : ['row' => $row, 'flags' => $progress->openFlags($term, $row['department'])];
    }

    /**
     * Si quien mira ve el renglón, las observaciones y el avisar de Servicios Escolares: solo su jefe y el admin; ni la Subdirección
     * Académica ni el directivo (CV-S1, desde el 20 de noviembre de 2026).
     */
    private function seesSchoolServices(Actor $context): bool
    {
        return CatalogReviewQuery::allowsOn($context, CatalogValidationPermission::ReviewView, ReviewOwner::SCHOOL_SERVICES);
    }

    private function user(): User
    {
        return app(Actor::class)->user() ?? abort(403);
    }
};
