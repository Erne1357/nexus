<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Livewire\Concerns\WithModalityScope;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Staffing\Enums\StaffingPermission;
use App\Modules\Staffing\Jobs\RefreshTeacherLoads;
use App\Modules\Staffing\Models\TeacherTermLoad;
use App\Modules\Staffing\Queries\TeacherLoadRow;
use App\Modules\Staffing\Queries\TeacherLoadsBoard;
use App\Modules\Staffing\Queries\TeacherLoadsBoardQuery;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * Carga por docente (tarea 3.12, maqueta B «Mosaicos por estado», decidida por el usuario): las horas de cada docente
 * del departamento en el periodo activo, sumando todos los campus y todas sus modalidades (el tope es del docente, MS32,
 * supuesto por confirmar), en mosaicos agrupados por estado (exceden su tope,
 * sin carga, con horas libres, carga completa, honorarios). Tocar un mosaico abre el drawer con sus grupos por campus
 * y su semana. Lo que muestra sale de TeacherLoadsBoardQuery, que lee `teacher_term_loads`; mientras el periodo no
 * tenga filas, «Calcular ahora» despacha el job RefreshTeacherLoads.
 *
 * La página es de una modalidad (`?modalidad=`, WithModalityScope; M1; sin select con una sola, MS16): decide qué
 * departamentos ve el usuario (el permiso es de la modalidad, AZ-S17) y qué carga del departamento se muestra. El
 * departamento es el del jefe; con varios, o para Subdirección y admin, `?depto=<código>` lo elige. Entran quienes
 * ven la carga por docente (TeacherTermLoadPolicy::viewAny) y la policy se vuelve a preguntar en cada acción.
 */
new #[Title('Carga por docente')] class extends Component
{
    use Toast;
    use WithModalityScope;

    /** Las secciones del tablero, en orden: estado, título, tono y icono. */
    private const array SECTIONS = [
        TeacherLoadRow::OVER => ['Exceden su tope', 'error', 'o-x-circle'],
        TeacherLoadRow::NONE => ['Sin carga', 'warning', 'o-clock'],
        TeacherLoadRow::PART => ['Con horas libres', 'info', 'o-arrow-trending-down'],
        TeacherLoadRow::FULL => ['Carga completa', 'success', 'o-check-circle'],
        TeacherLoadRow::HONORARIOS => ['Honorarios', 'draft', 'o-pencil-square'],
    ];

    /** Código del departamento; '' es el primero que puede ver. */
    #[Url(as: 'depto')]
    public string $department = '';

    #[Url(as: 'q')]
    public string $search = '';

    /** Valor de PositionKind; '' es cualquier plaza. */
    #[Url(as: 'plaza')]
    public string $plaza = '';

    /** Estado del filtro segmentado: 'todos' o el de una sección. */
    #[Url(as: 'estado')]
    public string $state = 'todos';

    /** El docente del drawer. */
    #[Locked]
    public ?string $teacherId = null;

    public bool $showDrawer = false;

    #[Locked]
    public ?string $termId = null;

    #[Locked]
    public ?string $modalityId = null;

    #[Locked]
    public ?string $departmentId = null;

    /**
     * @var list<array{code: string, name: string}>
     */
    #[Locked]
    public array $departments = [];

    #[Locked]
    public ?string $message = null;

    public function mount(Actor $context): void
    {
        abort_unless($context->allowsAny(StaffingPermission::TeacherLoadView), 403);

        $this->resolve($context);
        $this->updatedPlaza();
        $this->updatedState();
    }

    public function updatedDepartment(Actor $context): void
    {
        $this->reset('teacherId', 'showDrawer');
        $this->resolve($context);
    }

    /**
     * Otra modalidad en el select: los departamentos y la carga de esa modalidad; si no ve el departamento ahí, el primero.
     */
    public function updatedModalidad(Actor $context): void
    {
        $modalityId = $this->requiredModality()->getKey();
        $department = Department::query()->where('code', $this->department)->first();

        if ($department === null || ! $context->allows(StaffingPermission::TeacherLoadView, Target::of(modality: $modalityId, unit: $department->getKey()))) {
            $this->department = '';
        }

        $this->updatedDepartment($context);
    }

    public function updatedState(): void
    {
        $this->state = $this->state === 'todos' || array_key_exists($this->state, self::SECTIONS) ? $this->state : 'todos';
    }

    public function updatedPlaza(): void
    {
        $this->plaza = PositionKind::tryFrom($this->plaza) === null ? '' : $this->plaza;
    }

    /**
     * Abre el drawer de un docente del tablero; uno que no es del departamento es 404.
     */
    public function open(string $id, TeacherLoadsBoardQuery $query): void
    {
        $board = $this->board($query);

        abort_if($board === null || ! array_any($board->rows, fn (TeacherLoadRow $row): bool => $row->teacherId === $id), 404);

        $this->teacherId = $id;
        $this->showDrawer = true;
    }

    public function updatedShowDrawer(bool $open): void
    {
        if (! $open) {
            $this->teacherId = null;
        }
    }

    /**
     * Despacha el recálculo del periodo (RefreshTeacherLoads).
     */
    public function calculate(): void
    {
        abort_if($this->termId === null, 403);
        $this->authorizeView();

        RefreshTeacherLoads::dispatch($this->termId);
        $this->success('Calculando la carga', 'Recarga la página en unos segundos.');
    }

    /**
     * @return array<string, mixed>
     */
    public function with(TeacherLoadsBoardQuery $query): array
    {
        $board = $this->board($query);

        $scope = ['modalityOptions' => $this->modalityOptions(), 'shownModality' => $this->modalityId === null ? null : $this->modalityOptions()->first(fn (Modality $option): bool => $option->getKey() === $this->modalityId)];

        if ($board === null) {
            return ['board' => null, ...$scope];
        }

        $needle = Str::of($this->search)->squish()->ascii()->lower()->toString();
        $visible = array_values(array_filter($board->rows, fn (TeacherLoadRow $row): bool => ($this->plaza === '' || in_array($this->plaza, $row->positionKinds, true))
            && ($needle === '' || str_contains(Str::of($row->name.' '.$row->rfc)->ascii()->lower()->toString(), $needle))));

        $stateOptions = ['todos' => 'Todos', TeacherLoadRow::OVER => 'Exceden', TeacherLoadRow::NONE => 'Sin carga', TeacherLoadRow::PART => 'Libres', TeacherLoadRow::FULL => 'Completa', TeacherLoadRow::HONORARIOS => 'Honorarios'];
        $stateCounts = array_map(fn (string $option): int => $option === 'todos'
            ? count($visible)
            : count(array_filter($visible, fn (TeacherLoadRow $row): bool => $row->status() === $option)), array_combine(array_keys($stateOptions), array_keys($stateOptions)));

        $sections = [];

        foreach (self::SECTIONS as $status => [$title, $tone, $icon]) {
            if ($this->state !== 'todos' && $this->state !== $status) {
                continue;
            }

            $rows = array_values(array_filter($visible, fn (TeacherLoadRow $row): bool => $row->status() === $status));

            if ($rows !== []) {
                $sections[$status] = ['title' => $title, 'tone' => $tone, 'icon' => $icon, 'rows' => $rows, 'totals' => $board->totals($rows)];
            }
        }

        $totals = $board->totals();
        // La carga del departamento en la modalidad de la página (M1: una por departamento y modalidad).
        $loads = AcademicLoad::query()->where('term_id', $this->termId)->where('department_id', $this->departmentId)->where('modality_id', $this->modalityId)->get(['status']);

        return [
            ...$scope,
            'board' => $board,
            'department' => KeyLookup::find(Department::query()->withTrashed(), $this->departmentId),
            'term' => KeyLookup::find(Term::query(), $this->termId),
            'loads' => $loads,
            'totals' => $totals,
            'sections' => $sections,
            'visibleCount' => count($visible),
            'stateOptions' => $stateOptions,
            'stateCounts' => $stateCounts,
            'campusTones' => array_flip(array_keys($totals['byCampus'])),
            'plazaOptions' => array_map(fn (PositionKind $kind): array => ['id' => $kind->value, 'name' => $kind->label()], PositionKind::cases()),
            'selected' => $this->teacherId === null ? null : collect($board->rows)->first(fn (TeacherLoadRow $row): bool => $row->teacherId === $this->teacherId),
        ];
    }

    /**
     * Resuelve con la credencial el periodo, la modalidad (la elegida o la de omisión, MS30; queda fija en `?modalidad=`)
     * y el departamento. Uno pedido por la URL que el usuario no ve es 403.
     */
    private function resolve(Actor $context): void
    {
        $this->termId = $this->modalityId = $this->departmentId = null;
        $this->departments = [];

        $modality = $this->requiredModality();
        $this->modalidad = $modality->code;
        $term = $context->activeTerm();

        if ($term === null) {
            $this->message = 'No hay un periodo activo: elige uno en tu credencial.';

            return;
        }

        /* La pantalla muestra todos los campus juntos (S6). */
        $departments = Department::query()->whereNull('deleted_at')->where('is_active', true)->orderBy('code')->get()
            ->filter(fn (Department $department): bool => $context->allows(StaffingPermission::TeacherLoadView, Target::of(modality: $modality->getKey(), unit: $department->getKey())))
            ->values();

        $chosen = $this->department === '' ? $departments->first() : $departments->first(fn (Department $department): bool => $department->code === $this->department);

        abort_if($this->department !== '' && $chosen === null, 403);

        $this->departments = $departments->map(fn (Department $department): array => ['code' => $department->code, 'name' => "{$department->code} · {$department->name}"])->all();

        if ($chosen === null) {
            $this->message = "No tienes un departamento con carga por docente en {$modality->name}.";

            return;
        }

        $this->department = $chosen->code;
        $this->termId = $term->getKey();
        $this->modalityId = $modality->getKey();
        $this->departmentId = $chosen->getKey();
        $this->message = null;
    }

    private function board(TeacherLoadsBoardQuery $query): ?TeacherLoadsBoard
    {
        if ($this->departmentId === null || $this->termId === null) {
            return null;
        }

        [$term, $department] = $this->authorizeView();

        return $query->forDepartment($term, $department);
    }

    /**
     * El periodo y el departamento de la página, con la policy de ver la carga: una petición puede llegar de una
     * página que abrió otro usuario.
     *
     * @return array{0: Term, 1: Department}
     */
    private function authorizeView(): array
    {
        abort_if($this->departmentId === null || $this->termId === null || $this->modalityId === null, 403);

        $department = KeyLookup::findOrFail(Department::query()->withTrashed(), $this->departmentId);
        $modality = KeyLookup::findOrFail(Modality::query()->withTrashed(), $this->modalityId);

        Gate::authorize('viewAny', [TeacherTermLoad::class, $department, $modality]);

        return [KeyLookup::findOrFail(Term::query(), $this->termId), $department];
    }
};
