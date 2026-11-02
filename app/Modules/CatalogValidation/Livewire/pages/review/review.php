<?php

use App\Modules\Academic\Enums\SubjectKind;
use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Actions\CorrectRecord;
use App\Modules\CatalogValidation\Actions\FlagRecord;
use App\Modules\CatalogValidation\Actions\ReopenReview;
use App\Modules\CatalogValidation\Actions\ResolveFlag;
use App\Modules\CatalogValidation\Actions\ValidateReview;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewFlag;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Queries\CatalogContentsQuery;
use App\Modules\CatalogValidation\Queries\CatalogRecordsQuery;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Rules\RoomRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

/**
 * Validación de catálogos del departamento (docs/dominio/reglas-de-negocio.md, "Validación de catálogos"),
 * variante C «tablero de tarjetas» (DESIGN.md). Toma el periodo y el campus activos de la credencial y, de la URL,
 * el departamento (`?department=`) y el catálogo abierto (`?catalogo=`).
 *
 * Tablero: el avance (HC-14) y una tarjeta por revisión, con Validar o Reabrir. Lista de un catálogo: búsqueda,
 * filtros, tabla y un drawer por registro para corregirlo (CV1, CV2) y ver, poner y resolver observaciones (CV4).
 *
 * El jefe cubre su departamento; Subdirección y el admin abren cualquiera en lectura, y solo resuelven
 * observaciones (CV-S1). Toda escritura pasa por su Action, después de autorizar con CatalogReviewPolicy en cada
 * método público, porque la petición puede llegar con una pantalla que abrió otra sesión. Los errores de las
 * Actions (vetos, revisión validada) salen en la pantalla.
 */
new #[Title('Validación de catálogos')] class extends Component
{
    use Toast, WithPagination;

    /** El departamento abierto; vacío es el primero que cubre el jefe. Se valida en cada petición. */
    #[Url]
    public string $department = '';

    /** El catálogo de la lista abierta (`teachers`, `rooms`, `subjects`, `study_plans`, `careers`); vacío es el tablero. */
    #[Url(as: 'catalogo')]
    public string $catalog = '';

    public string $search = '';

    /** '' (todos), 'active' o 'inactive'. */
    public string $status = '';

    /** '' (todos), 'with' (con observación abierta) o 'without'. */
    public string $flagFilter = '';

    public bool $showConfirm = false;

    /** 'validate' o 'reopen'; solo lo pone confirm(). */
    #[Locked]
    public string $confirmAction = '';

    #[Locked]
    public string $confirmCatalog = '';

    public bool $showDrawer = false;

    /** El registro del drawer; null si no hay. El navegador no lo puede cambiar. */
    #[Locked]
    public ?string $editingId = null;

    public string $name = '';

    public string $theory_hours = '';

    public string $practice_hours = '';

    public string $credits = '';

    public string $kind = '';

    public bool $needs_lab = false;

    public string $version = '';

    public string $semesters_count = '';

    public string $total_credits = '';

    public string $valid_from = '';

    public string $type = '';

    public string $capacity = '';

    public string $features = '';

    public bool $is_active = true;

    /** El tipo de la observación nueva. */
    public string $flag = '';

    public string $comment = '';

    /** Lo resuelto en esta petición: departamentos, selección, periodo, campus y revisiones. */
    private ?array $resolved = null;

    public function mount(): void
    {
        $state = $this->state();

        if ($this->department === '' && $state['department'] !== null) {
            $this->department = $state['department']->getKey();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function with(CatalogReviewQuery $reviews, CatalogRecordsQuery $records, CatalogContentsQuery $contents): array
    {
        $state = $this->state();
        $data = ['departments' => $state['departments'], 'department' => $state['department'], 'term' => $state['term'], 'campus' => $state['campus']];

        $empty = match (true) {
            $state['department'] === null => 'department',
            $state['term'] === null => 'term',
            $state['campus'] === null => 'campus',
            $state['reviews']->isEmpty() => 'reviews',
            default => null,
        };

        if ($empty !== null) {
            return [...$data, 'empty' => $empty, 'cards' => collect(), 'progress' => null, 'current' => null, 'confirming' => null];
        }

        $open = $this->openCatalog();
        $openReview = $open === null ? null : $state['reviews']->first(fn (CatalogReview $review): bool => $review->catalog === $open);

        /*
        | El tablero arma las cinco tarjetas y el avance (HC-14); la lista, solo la revisión abierta y sin preguntar
        | por el veto de Reabrir (confirm() lo vuelve a preguntar al pedirlo).
        */
        $current = $openReview === null ? null : $this->card($openReview, $reviews, false);
        $cards = $current === null ? $state['reviews']->map(fn (CatalogReview $review): array => $this->card($review, $reviews, true)) : collect([$current]);
        $data += ['empty' => null, 'cards' => $cards, 'progress' => $current === null ? $this->progress($state, $reviews) : null, 'current' => $current];

        $data += $this->confirmation($cards);

        return [...$data, ...($current === null ? ['page' => null] : $this->list($current, $records, $contents))];
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'flagFilter', 'department', 'catalog'], true)) {
            $this->resetPage();
        }

        if (in_array($property, ['department', 'catalog'], true)) {
            $this->reset('search', 'status', 'flagFilter', 'showDrawer', 'editingId');
        }
    }

    /**
     * Pide confirmar Validar o Reabrir una revisión (el modal del tablero y de la lista).
     */
    public function confirm(string $action, string $catalog): void
    {
        $review = $this->reviewOf($catalog);
        $this->resetValidation();

        if ($action === 'reopen') {
            $this->authorize('reopen', $review);
            $vetoes = app(CatalogReviewQuery::class)->reopeningVetoes($review);

            if ($vetoes !== []) {
                throw ValidationException::withMessages(['status' => $vetoes]);
            }
        } else {
            abort_unless($action === 'validate', 404);
            $this->authorize('validate', $review);
        }

        $this->confirmAction = $action;
        $this->confirmCatalog = $catalog;
        $this->showConfirm = true;
    }

    public function confirmed(ValidateReview $validateReview, ReopenReview $reopenReview): void
    {
        $review = $this->reviewOf($this->confirmCatalog);
        $action = $this->confirmAction;
        $this->showConfirm = false;
        $this->reset('confirmAction', 'confirmCatalog');

        if ($action === 'reopen') {
            $this->authorize('reopen', $review);
            $reopenReview->handle($review, $this->user());
            $this->success('Revisión reabierta', e($review->title));
        } else {
            $this->authorize('validate', $review);
            $validateReview->handle($review, $this->user());
            $this->success('Revisión validada', e($review->title));
        }
    }

    public function cancelConfirm(): void
    {
        $this->showConfirm = false;
        $this->reset('confirmAction', 'confirmCatalog');
    }

    /**
     * Abre el drawer de un registro: sus datos, sus observaciones y, si la policy lo deja, el formulario.
     */
    public function edit(string $id, CatalogContentsQuery $contents): void
    {
        $review = $this->currentReview();
        $this->authorize('view', $review);
        $record = $contents->findOrFail($review, $id);

        $this->resetValidation();
        $this->reset('flag', 'comment');
        $this->editingId = $record->getKey();
        $this->fill($this->formOf($review->catalog, $record));
        $this->showDrawer = true;
    }

    public function closeDrawer(): void
    {
        $this->showDrawer = false;
        $this->editingId = null;
    }

    public function save(CorrectRecord $correctRecord, CatalogContentsQuery $contents): void
    {
        $review = $this->currentReview();
        $this->authorize('correct', $review);
        abort_if($review->catalog === Catalog::Teachers, 403, 'Los docentes se corrigen en su ficha.');
        $record = $contents->findOrFail($review, (string) $this->editingId);

        $record = $correctRecord->handle($review, $record, $this->validatedRecord($review->catalog), $this->user());

        $this->success(match ($review->catalog) {
            Catalog::Rooms => 'Salón actualizado',
            Catalog::StudyPlans => 'Plan actualizado',
            Catalog::Careers => 'Carrera actualizada',
            default => 'Materia actualizada',
        }, $this->describe($review, $record));
        $this->closeDrawer();
    }

    public function toggleActive(string $id, CorrectRecord $correctRecord, CatalogContentsQuery $contents): void
    {
        $review = $this->currentReview();
        $this->authorize('correct', $review);
        abort_unless(in_array($review->catalog, [Catalog::Subjects, Catalog::Rooms], true), 403, 'La actividad de carreras y planes no se cambia aquí.');
        $record = $contents->findOrFail($review, $id);

        try {
            $record = $correctRecord->handle($review, $record, ['is_active' => ! $record->is_active], $this->user());
        } catch (ValidationException $exception) {
            $this->error('No se pudo cambiar '.e($this->describe($review, $record)), e(implode(' ', Arr::flatten($exception->errors()))));

            return;
        }

        $this->success($record->is_active ? 'Registro activado' : 'Registro desactivado', $this->describe($review, $record));
    }

    public function addFlag(FlagRecord $flagRecord, CatalogContentsQuery $contents): void
    {
        $review = $this->currentReview();
        $this->authorize('flag', $review);
        $record = $contents->findOrFail($review, (string) $this->editingId);

        $data = $this->validate([
            'flag' => ['required', Rule::enum(ReviewFlag::class)],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $flagRecord->handle($review, $record, ReviewFlag::from($data['flag']), $data['comment'] ?? null, $this->user());

        $this->reset('flag', 'comment');
        $this->success('Observación agregada', $this->describe($review, $record));
    }

    public function resolveFlag(string $id, ResolveFlag $resolveFlag, CatalogRecordsQuery $records): void
    {
        $review = $this->currentReview();
        $this->authorize('resolveFlag', $review);

        $resolveFlag->handle($review, $records->findFlag($review, $id), $this->user());

        $this->success('Observación resuelta', e($review->title));
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'name' => 'nombre',
            'theory_hours' => 'horas de teoría',
            'practice_hours' => 'horas de práctica',
            'credits' => 'créditos',
            'kind' => 'tipo',
            'version' => 'versión',
            'semesters_count' => 'semestres',
            'total_credits' => 'créditos del plan',
            'valid_from' => 'vigente desde',
            'type' => 'tipo',
            'capacity' => 'capacidad',
            'features' => 'características',
            'flag' => 'tipo de observación',
            'comment' => 'comentario',
        ];
    }

    /**
     * Lo resuelto de la petición, memorizado: los departamentos que el usuario abre, el seleccionado (403 si
     * `?department=` no es de los suyos o no hay ninguno), el periodo y el campus activos y las revisiones.
     *
     * @return array{departments: Collection<int, Department>, department: ?Department, term: ?Term, campus: ?Campus, reviews: Collection<int, CatalogReview>}
     */
    private function state(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $context = app(ScopedContext::class);
        $departments = app(CatalogReviewQuery::class)->departmentsFor($context);
        abort_if($departments->isEmpty(), 403);

        if ($this->department !== '') {
            $department = $departments->first(fn (Department $candidate): bool => $candidate->getKey() === $this->department);
            abort_if($department === null, 403);
        } else {
            $department = $context->isAdmin() ? null : $departments->first(fn (Department $candidate): bool => $context->coversDepartment(Role::JefeDepartamento, $candidate->getKey()));
        }

        $term = $context->activeTerm();
        $campus = $context->activeCampus();
        $reviews = $department === null || $term === null || $campus === null
            ? new Collection
            : app(CatalogReviewQuery::class)->forDepartment($term, $campus, $department)->load(['campus', 'department', 'validator'])
                ->filter(fn (CatalogReview $review): bool => Gate::allows('view', $review))
                ->values()
                ->each(fn (CatalogReview $review) => $review->setRelation('term', $term));

        return $this->resolved = compact('departments', 'department', 'term', 'campus', 'reviews');
    }

    private function user(): User
    {
        return app(ScopedContext::class)->user() ?? abort(403);
    }

    private function openCatalog(): ?Catalog
    {
        return Catalog::tryFrom($this->catalog);
    }

    /**
     * La revisión del catálogo dado en el departamento, periodo y campus de la pantalla (404 si no existe).
     */
    private function reviewOf(string $catalog): CatalogReview
    {
        return $this->state()['reviews']->first(fn (CatalogReview $review): bool => $review->catalog->value === $catalog) ?? abort(404);
    }

    private function currentReview(): CatalogReview
    {
        return $this->reviewOf($this->openCatalog()?->value ?? '');
    }

    /**
     * Lo que muestra la tarjeta de una revisión y lo que el usuario puede hacer con ella.
     *
     * @return array<string, mixed>
     */
    private function card(CatalogReview $review, CatalogReviewQuery $reviews, bool $withVetoes): array
    {
        $validated = $review->status === ReviewStatus::Validated;
        $isHead = Gate::allows('flag', $review);
        $canReopen = Gate::allows('reopen', $review);
        $vetoes = $withVetoes && $validated && $canReopen ? $reviews->reopeningVetoes($review) : [];
        $isTeachers = $review->catalog === Catalog::Teachers;

        $editReason = match (true) {
            $isTeachers => 'Los docentes se corrigen en su ficha.',
            ! $isHead => 'Solo el jefe del departamento corrige.',
            ! Gate::allows('correct', $review) => 'Este campus no deja que los jefes corrijan salones; solo pueden observarlos.',
            $validated => 'La revisión ya está validada: reábrela para corregir.',
            default => null,
        };

        return [
            'review' => $review,
            'summary' => $reviews->summary($review),
            'canValidate' => Gate::allows('validate', $review),
            'canReopen' => $canReopen,
            'reopenVetoes' => $vetoes,
            'canResolve' => Gate::allows('resolveFlag', $review),
            'canEdit' => ! $validated && ! $isTeachers && Gate::allows('correct', $review),
            'canFlag' => ! $validated && $isHead,
            'canToggle' => ! $validated && in_array($review->catalog, [Catalog::Subjects, Catalog::Rooms], true) && Gate::allows('correct', $review),
            'toggleReason' => in_array($review->catalog, [Catalog::Careers, Catalog::StudyPlans], true)
                ? 'Carreras y planes no se activan ni se desactivan aquí: pon la observación «Debe desactivarse».'
                : $editReason,
            'editReason' => $editReason,
            'flagReason' => match (true) {
                ! $isHead => 'Solo el jefe del departamento pone observaciones.',
                $validated => 'La revisión ya está validada: reábrela para observar.',
                default => null,
            },
        ];
    }

    /**
     * «N de 5 validados» (HC-14): los catálogos que exige el campus (los generales y, si usa salones, los salones) y
     * los que todavía bloquean la salida de borrador de la carga (CatalogReviewQuery::pendingFor); una revisión que
     * falta cuenta como pendiente. `items` trae cada catálogo exigido con su revisión, si existe y el usuario la ve.
     *
     * @param  array{term: ?Term, campus: ?Campus, department: ?Department, reviews: Collection<int, CatalogReview>}  $state
     * @return array{validated: int, total: int, left: int, items: list<array{catalog: Catalog, review: ?CatalogReview}>}
     */
    private function progress(array $state, CatalogReviewQuery $reviews): array
    {
        $required = array_values(array_filter(Catalog::cases(), fn (Catalog $catalog): bool => $catalog->isGeneral() || $state['campus']->profile->usesRooms));
        $pending = $reviews->pendingFor($state['term'], $state['campus'], $state['department']);
        $existing = $state['reviews']->keyBy(fn (CatalogReview $review): string => $review->catalog->value);

        return [
            'validated' => count($required) - count($pending),
            'total' => count($required),
            'left' => count($pending),
            'items' => array_map(fn (Catalog $catalog): array => ['catalog' => $catalog, 'review' => $existing->get($catalog->value)], $required),
        ];
    }

    /**
     * La revisión que espera confirmación, con lo que valida, para el modal.
     *
     * @param  Collection<int, array<string, mixed>>  $cards
     * @return array<string, mixed>
     */
    private function confirmation(Collection $cards): array
    {
        $card = $this->confirmAction === '' ? null : $cards->first(fn (array $card): bool => $card['review']->catalog->value === $this->confirmCatalog);

        return ['confirming' => $card];
    }

    /**
     * La tabla de un catálogo y lo que el drawer abierto necesita.
     *
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function list(array $current, CatalogRecordsQuery $records, CatalogContentsQuery $contents): array
    {
        /** @var CatalogReview $review */
        $review = $current['review'];
        $page = $records->page($review, $this->search, match ($this->status) {
            'active' => true,
            'inactive' => false,
            default => null,
        }, $this->flagFilter);

        $editing = null;

        if ($this->showDrawer && $this->editingId !== null) {
            try {
                $record = $contents->findOrFail($review, $this->editingId);
                $editing = ['record' => $record, 'flags' => $records->flagsOf($review, $record), 'locked' => $this->lockedOf($review, $record)];
            } catch (ModelNotFoundException) {
                $this->closeDrawer();
            }
        }

        return [
            'page' => $page,
            'flagMap' => $records->openFlags($review, $page->getCollection()->map->getKey()),
            'headers' => $this->headersOf($review->catalog),
            'rowDecoration' => ['text-neutral' => fn (Model $record): bool => ! $record->is_active],
            'editing' => $editing,
            'statuses' => [['id' => 'active', 'name' => 'Activos'], ['id' => 'inactive', 'name' => 'Inactivos']],
            'flagFilters' => [['id' => 'with', 'name' => 'Con observación abierta'], ['id' => 'without', 'name' => 'Sin observación abierta']],
            'flagOptions' => collect(ReviewFlag::cases())->filter(fn (ReviewFlag $flag): bool => $flag->appliesTo($review->catalog))->map(fn (ReviewFlag $flag): array => ['id' => $flag->value, 'name' => $flag->label()])->values()->all(),
            'kinds' => array_map(fn (SubjectKind $kind): array => ['id' => $kind->value, 'name' => $kind->label()], SubjectKind::cases()),
            'roomTypes' => array_map(fn (RoomType $type): array => ['id' => $type->value, 'name' => $type->label()], RoomType::cases()),
        ];
    }

    /**
     * Las columnas propias de cada catálogo (DESIGN.md: las descriptivas largas se ocultan debajo de 1200 px).
     *
     * @return list<array<string, mixed>>
     */
    private function headersOf(Catalog $catalog): array
    {
        $name = ['key' => 'name', 'label' => 'Nombre', 'class' => 'min-w-36 min-[1200px]:min-w-52'];
        $closing = [['key' => 'flags', 'label' => 'Observación'], ['key' => 'is_active', 'label' => 'Estado']];

        return [...match ($catalog) {
            Catalog::Subjects => [
                ['key' => 'code', 'label' => 'Clave'],
                [...$name, 'label' => 'Materia'],
                ['key' => 'theory_hours', 'label' => 'HT', 'class' => 'text-end'],
                ['key' => 'practice_hours', 'label' => 'HP', 'class' => 'text-end'],
                ['key' => 'credits', 'label' => 'Créd.', 'class' => 'text-end'],
            ],
            Catalog::Rooms => [
                ['key' => 'code', 'label' => 'Clave'],
                ['key' => 'building', 'label' => 'Edificio', 'class' => 'max-[1023px]:hidden'],
                ['key' => 'capacity', 'label' => 'Capacidad', 'class' => 'text-end'],
                ['key' => 'type', 'label' => 'Tipo', 'class' => 'max-[1023px]:hidden'],
            ],
            Catalog::StudyPlans => [
                ['key' => 'code', 'label' => 'Clave'],
                ['key' => 'career', 'label' => 'Carrera'],
                [...$name, 'label' => 'Plan', 'class' => 'max-[1200px]:hidden'],
            ],
            Catalog::Careers => [['key' => 'code', 'label' => 'Clave'], [...$name, 'label' => 'Carrera']],
            Catalog::Teachers => [['key' => 'employee_number', 'label' => 'Número'], [...$name, 'label' => 'Docente']],
        }, ...$closing];
    }

    /**
     * Lo que ata el registro al SII o a su departamento y el drawer muestra deshabilitado (CV-S3).
     *
     * @return list<array{label: string, value: ?string}>
     */
    private function lockedOf(CatalogReview $review, Model $record): array
    {
        return match ($review->catalog) {
            Catalog::Subjects, Catalog::Careers => [
                ['label' => 'Clave', 'value' => $record->code],
                ['label' => 'Departamento', 'value' => $review->department?->name],
            ],
            Catalog::StudyPlans => [
                ['label' => 'Clave', 'value' => $record->code],
                ['label' => 'Carrera', 'value' => $record->career?->name],
            ],
            Catalog::Rooms => [
                ['label' => 'Clave', 'value' => $record->code],
                ['label' => 'Edificio', 'value' => $record->building?->name ?? $record->building?->code],
                ['label' => 'Departamento', 'value' => $review->department?->name],
            ],
            Catalog::Teachers => [['label' => 'Número de empleado', 'value' => $record->employee_number]],
        };
    }

    /**
     * Los campos del formulario del drawer con los valores del registro.
     *
     * @return array<string, mixed>
     */
    private function formOf(Catalog $catalog, Model $record): array
    {
        $common = ['name' => (string) $record->name, 'is_active' => (bool) $record->is_active];

        return $common + match ($catalog) {
            Catalog::Subjects => [
                'theory_hours' => (string) $record->theory_hours,
                'practice_hours' => (string) $record->practice_hours,
                'credits' => (string) $record->credits,
                'kind' => $record->kind?->value ?? '',
                'needs_lab' => (bool) $record->needs_lab,
            ],
            Catalog::StudyPlans => [
                'version' => (string) $record->version,
                'semesters_count' => (string) $record->semesters_count,
                'total_credits' => (string) $record->total_credits,
                'valid_from' => $record->valid_from?->toDateString() ?? '',
            ],
            Catalog::Rooms => [
                'type' => $record->type->value,
                'capacity' => (string) $record->capacity,
                'features' => implode(', ', $record->features ?? []),
            ],
            default => [],
        };
    }

    /**
     * Los datos que acepta la Action del módulo dueño, validados en forma y con sus tipos; lo que ata el registro
     * (clave, departamento, carrera, edificio) no se manda, y CorrectRecord lo quitaría de todos modos.
     *
     * @return array<string, mixed>
     */
    private function validatedRecord(Catalog $catalog): array
    {
        $common = ['is_active' => ['boolean']];

        return match ($catalog) {
            Catalog::Subjects => $this->subjectData($common),
            Catalog::Careers => $this->validate(['name' => ['required', 'string', 'max:255']]),
            Catalog::StudyPlans => $this->planData(),
            Catalog::Rooms => $this->roomData($common),
            Catalog::Teachers => abort(403),
        };
    }

    /**
     * @param  array<string, list<string>>  $common
     * @return array<string, mixed>
     */
    private function subjectData(array $common): array
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'theory_hours' => ['required', 'integer', 'min:0', 'max:32767'],
            'practice_hours' => ['required', 'integer', 'min:0', 'max:32767'],
            'credits' => ['required', 'integer', 'min:0', 'max:32767'],
            'kind' => ['nullable', Rule::enum(SubjectKind::class)],
            'needs_lab' => ['boolean'],
        ] + $common);

        return [
            ...$data,
            'theory_hours' => (int) $data['theory_hours'],
            'practice_hours' => (int) $data['practice_hours'],
            'credits' => (int) $data['credits'],
            'kind' => (string) $data['kind'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function planData(): array
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'version' => ['required', 'string', 'max:255'],
            'semesters_count' => ['nullable', 'integer', 'min:1', 'max:32767'],
            'total_credits' => ['nullable', 'integer', 'min:0', 'max:32767'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return [
            ...$data,
            'semesters_count' => ($data['semesters_count'] ?? '') === '' ? null : (int) $data['semesters_count'],
            'total_credits' => ($data['total_credits'] ?? '') === '' ? null : (int) $data['total_credits'],
            'valid_from' => ($data['valid_from'] ?? '') === '' ? null : $data['valid_from'],
        ];
    }

    /**
     * @param  array<string, list<string>>  $common
     * @return array<string, mixed>
     */
    private function roomData(array $common): array
    {
        $data = $this->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::enum(RoomType::class)],
            'capacity' => ['required', 'integer', 'min:1', 'max:100000'],
            'features' => ['nullable', 'string', 'max:1000', app(RoomRules::class)->validateFeatureLength(...)],
        ] + $common);

        return [
            ...$data,
            'name' => $data['name'] ?? null,
            'capacity' => (int) $data['capacity'],
            'features' => app(RoomRules::class)->featureList((string) $data['features']),
        ];
    }

    /**
     * Clave y nombre para el toast, escapados: MaryUI lo pinta con x-html.
     */
    private function describe(CatalogReview $review, Model $record): string
    {
        $code = $review->catalog === Catalog::Teachers ? $record->employee_number : $record->code;

        return e("{$code} · {$record->name}");
    }
};
