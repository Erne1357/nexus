<?php

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Actions\RemindPendingReviews;
use App\Modules\CatalogValidation\Actions\ResolveFlag;
use App\Modules\CatalogValidation\Jobs\RemindPendingReviewsJob;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Queries\ValidationProgressQuery;
use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Enums\StageStatus;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Core\Queries\StageWindow;
use App\Modules\Core\Queries\TermCalendarQuery;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * Avance de validación (tarea 2.5; docs/dominio/reglas-de-negocio.md, "Validación de catálogos"), variante A
 * «matriz» (DESIGN.md): el avance global del periodo activo, la ventana de la etapa de validación de catálogos y
 * una fila por departamento con el estado de cada catálogo, de las observaciones y de su carga. Lo ven y recuerdan
 * Subdirección y el admin (CatalogReviewPolicy::viewProgress y remind, supuesto por confirmar); cada método público
 * vuelve a autorizar, porque la petición puede llegar con una pantalla que abrió otra sesión. Resolver una
 * observación desde el drawer sigue a CatalogReviewPolicy::resolveFlag sobre la revisión de la observación.
 */
new #[Title('Avance de validación')] class extends Component
{
    use Toast;

    #[Session]
    public string $search = '';

    /** El campus filtrado, entre los abiertos; vacío son todos. */
    #[Session]
    public string $campus = '';

    /** '' (todos), 'pending' (con algo sin validar) o 'validated' (todo validado). */
    #[Session]
    public string $status = '';

    public bool $showDrawer = false;

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
    public function with(ValidationProgressQuery $progress, TermCalendarQuery $calendar, ScopedContext $context): array
    {
        Gate::authorize('viewProgress', CatalogReview::class);

        $term = $context->activeTerm();
        $open = $term === null ? new Collection : $progress->openCampuses($term);
        $campuses = $this->scope($open);

        if ($term === null || $open->isEmpty()) {
            return ['term' => $term, 'open' => $open, 'campuses' => $campuses, 'board' => null, 'window' => null, 'detail' => null];
        }

        $board = $progress->board($term, $campuses, $this->search, $this->status);

        return [
            'term' => $term,
            'open' => $open,
            'campuses' => $campuses,
            'board' => $board,
            'window' => $this->window($calendar, $term, $campuses),
            'detail' => $this->showDrawer && $this->detailId !== null ? $this->detail($progress, $term, $campuses, $board) : null,
        ];
    }

    public function updated(string $property): void
    {
        if ($property === 'campus') {
            $this->reset('showDrawer', 'detailId');
        }
    }

    /**
     * Abre el drawer de un departamento activo.
     */
    public function open(string $id): void
    {
        Gate::authorize('viewProgress', CatalogReview::class);

        $department = KeyLookup::findOrFail(Department::query()->where('is_active', true), $id);

        $this->detailId = $department->getKey();
        $this->showDrawer = true;
    }

    /**
     * Recuerda a los jefes con pendientes. De un departamento, en línea y en cada campus del filtro; sin
     * departamento («Recordar a todos los pendientes»), de los departamentos de las filas visibles (búsqueda, estado y
     * campus) en un job en cola, porque tarda.
     */
    public function remind(RemindPendingReviews $remind, ValidationProgressQuery $progress, ScopedContext $context, ?string $departmentId = null): void
    {
        Gate::authorize('remind', CatalogReview::class);

        $term = $context->activeTerm() ?? abort(404);
        $campuses = $this->scope($progress->openCampuses($term));

        if ($departmentId === null) {
            $ids = collect($progress->board($term, $campuses, $this->search, $this->status)['rows'])
                ->filter(fn (array $row): bool => $row['remind_blocked_reason'] === null)
                ->map(fn (array $row): string => $row['department']->getKey())
                ->values()
                ->all();

            if ($ids === []) {
                $this->info('Nadie por recordar', 'Ningún departamento visible tiene jefe con catálogos pendientes.');

                return;
            }

            RemindPendingReviewsJob::dispatch($term->getKey(), $campuses->pluck('id')->all(), $this->user()->getKey(), $ids);
            $this->info('Enviando recordatorios a '.count($ids).(count($ids) === 1 ? ' departamento' : ' departamentos'), e($term->name));

            return;
        }

        $department = KeyLookup::findOrFail(Department::query()->where('is_active', true), $departmentId);
        $notified = $remind->handle($term, $campuses, $this->user(), [$department->getKey()]);

        if ($notified === 0) {
            $this->info('Nadie por recordar', 'Ningún jefe tiene catálogos pendientes que recordar.');

            return;
        }

        $this->success($notified === 1 ? 'Se avisó a 1 jefe' : "Se avisó a {$notified} jefes", e($department->name));
    }

    public function resolveFlag(string $id, ValidationProgressQuery $progress, ResolveFlag $resolveFlag): void
    {
        Gate::authorize('viewProgress', CatalogReview::class);

        $flag = $progress->findFlag($id);
        $review = $flag->review;
        Gate::authorize('resolveFlag', $review);

        $resolveFlag->handle($review, $flag, $this->user());

        $this->success('Observación resuelta', e($review->title));
    }

    /**
     * Los campus del filtro: el elegido si sigue abierto, y si no todos los abiertos.
     *
     * @param  Collection<int, Campus>  $open
     * @return Collection<int, Campus>
     */
    private function scope(Collection $open): Collection
    {
        $chosen = $this->campus === '' ? null : $open->firstWhere('id', $this->campus);

        return $chosen === null ? $open : collect([$chosen]);
    }

    /**
     * La ventana de la etapa de validación de catálogos y cuánto de ella pasó, si tiene fechas; con un solo campus,
     * la suya si la tiene propia.
     *
     * @param  Collection<int, Campus>  $campuses
     * @return array{window: StageWindow, elapsed: int}|null
     */
    private function window(TermCalendarQuery $calendar, Term $term, Collection $campuses): ?array
    {
        $window = collect($calendar->for($term, $campuses->count() === 1 ? $campuses->first() : null))
            ->first(fn (StageWindow $candidate): bool => $candidate->stage === Stage::CatalogValidation);

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
     * Lo del drawer de un departamento: su fila, sus observaciones abiertas y su último recordatorio.
     *
     * @param  Collection<int, Campus>  $campuses
     * @param  array<string, mixed>  $board
     * @return array<string, mixed>|null
     */
    private function detail(ValidationProgressQuery $progress, Term $term, Collection $campuses, array $board): ?array
    {
        $department = KeyLookup::find(Department::query(), $this->detailId);

        if ($department === null) {
            return null;
        }

        $row = collect($board['rows'])->first(fn (array $candidate): bool => $candidate['department']->getKey() === $this->detailId)
            ?? ($progress->board($term, $campuses, departmentId: $this->detailId)['rows'][0] ?? null);

        return $row === null ? null : ['row' => $row, 'flags' => $progress->openFlags($term, $department, $campuses)];
    }

    private function user(): User
    {
        return app(ScopedContext::class)->user() ?? abort(403);
    }
};
