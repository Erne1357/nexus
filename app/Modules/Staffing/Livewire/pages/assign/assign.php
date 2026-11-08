<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\Transitions\StartStaffing;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Actions\AssignTeacher;
use App\Modules\Staffing\Enums\RankingState;
use App\Modules\Staffing\Enums\StaffingPermission;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Models\StaffingRanking;
use App\Modules\Staffing\Queries\AssignmentBoard;
use App\Modules\Staffing\Queries\AssignmentBoardQuery;
use App\Modules\Staffing\Queries\BoardGroup;
use App\Modules\Staffing\Queries\GroupRankingQuery;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * Asignación docente (tarea 3.9; reglas-de-negocio.md, «Ranking docente (desempate)», HC-16): el jefe asigna el
 * docente de cada grupo de su departamento, en el periodo y el campus activos de la credencial, con el ranking a la
 * vista. Dos vistas que el usuario alterna y que se recuerdan (`?vista=materia|paneles`, y en la sesión para cuando
 * entra sin ella): por materia, con los tres primeros lugares de cada grupo sin docente en su renglón y el ranking
 * completo en su lugar (maqueta C), y dos paneles, la lista de grupos y el ranking y el historial del elegido (maqueta
 * A). `?grupo=<id>` abre ese grupo; `?depto=<código>` elige el departamento a quien ve varios.
 *
 * Asignar al primer lugar es un clic; fuera de él (o fuera del ranking) pide el motivo en el renglón con el lugar que
 * ocupa. Lo que rechaza AssignTeacher se ve en su lugar. Si la carga está en propuesta, quien puede la pasa a
 * asignación (StartStaffing) y ve los motivos si Scheduling lo veta; en otro estado que no sea asignación ni ajustes
 * es de solo lectura. La ven quienes ven el ranking del departamento (StaffingRankingPolicy::viewAny) y asignan
 * quienes pueden (GroupTeacherAssignmentPolicy::create), con la policy antes de cada método. Lo que muestra sale de
 * AssignmentBoardQuery y GroupRankingQuery; mientras algún grupo a la vista espera su ranking, consulta cada 5 s.
 */
new #[Title('Asignación docente')] class extends Component
{
    use Toast;

    private const array VIEWS = ['materia', 'paneles'];

    private const array STATES = ['todos', 'sin', 'con'];

    private const array TABS = ['ranking', 'historial'];

    /** La última vista que eligió el usuario, para cuando entra sin `?vista=`. */
    private const string VIEW_KEY = 'staffing.assign.view';

    /** 'materia' (por materia) o 'paneles' (dos paneles). */
    #[Url(as: 'vista')]
    public string $view = 'materia';

    /** Código del departamento; '' es el primero que puede ver. */
    #[Url(as: 'depto')]
    public string $department = '';

    /** El grupo elegido en los dos paneles, o el que se abre al entrar. */
    #[Url(as: 'grupo')]
    public string $groupId = '';

    #[Url(as: 'q')]
    public string $search = '';

    /** 'todos', 'sin' (sin docente) o 'con' (con docente). */
    #[Url(as: 'estado')]
    public string $state = 'todos';

    /** Clave de la materia; '' todas. */
    #[Url(as: 'materia')]
    public string $subjectFilter = '';

    /** Clave de la carrera dueña; '' todas. */
    #[Url(as: 'carrera')]
    public string $careerFilter = '';

    /** Semestre del grupo; '' todos. */
    #[Url(as: 'semestre')]
    public string $semesterFilter = '';

    /** Pestaña del grupo elegido en los dos paneles: 'ranking' o 'historial'. */
    public string $tab = 'ranking';

    /** En pantallas angostas, el grupo elegido de los dos paneles se abre como hoja a pantalla completa. */
    public bool $sheet = false;

    /** El motivo de asignar fuera del primer lugar (HC-16). */
    public string $reason = '';

    /** El periodo, el campus y el departamento con los que entró. El navegador no los puede cambiar. */
    #[Locked]
    public ?string $termId = null;

    #[Locked]
    public ?string $campusId = null;

    #[Locked]
    public ?string $departmentId = null;

    /**
     * Los departamentos del selector y, si no hay tablero que mostrar, por qué.
     *
     * @var list<array{code: string, name: string}>
     */
    #[Locked]
    public array $departments = [];

    #[Locked]
    public ?string $message = null;

    /**
     * Los grupos con el ranking completo abierto en la vista por materia.
     *
     * @var list<string>
     */
    #[Locked]
    public array $expanded = [];

    /** El grupo al que se desplaza la página al abrirse. */
    #[Locked]
    public ?string $scrollTo = null;

    /**
     * La asignación que espera su motivo: grupo, docente, su nombre, su lugar (null fuera del ranking), de cuántos y
     * de dónde se pidió ('tarjetas' o 'ranking').
     *
     * @var array{group: string, teacher: string, name: string, position: ?int, size: int, from: string}|null
     */
    #[Locked]
    public ?array $pending = null;

    /**
     * Lo que rechazó AssignTeacher al asignar al primer lugar, para mostrarlo junto al docente.
     *
     * @var array{group: string, teacher: string, from: string, message: string}|null
     */
    #[Locked]
    public ?array $failure = null;

    /**
     * Por qué no pasó la carga a asignación (el veto de Scheduling o del diagrama).
     *
     * @var list<string>
     */
    #[Locked]
    public array $vetoReasons = [];

    public function mount(Actor $context, AssignmentBoardQuery $query): void
    {
        abort_unless($context->allowsAny(StaffingPermission::RankingView), 403);

        if (! request()->query->has('vista')) {
            $this->view = (string) session(self::VIEW_KEY, 'materia');
        }

        $this->view = in_array($this->view, self::VIEWS, true) ? $this->view : 'materia';
        $this->updatedState();
        $this->resolve($context, $query);
        $this->openRequestedGroup($query);
    }

    /**
     * Cambiar de departamento suelta lo elegido del anterior.
     */
    public function updatedDepartment(Actor $context, AssignmentBoardQuery $query): void
    {
        $this->reset('groupId', 'subjectFilter', 'careerFilter', 'semesterFilter', 'expanded', 'scrollTo', 'pending', 'failure', 'vetoReasons', 'sheet', 'reason', 'tab');
        $this->resolve($context, $query);
    }

    public function updatedState(): void
    {
        $this->state = in_array($this->state, self::STATES, true) ? $this->state : 'todos';
    }

    public function setView(string $view): void
    {
        $this->view = in_array($view, self::VIEWS, true) ? $view : 'materia';
        session()->put(self::VIEW_KEY, $this->view);
        $this->pending = $this->failure = null;

        if ($this->view === 'materia' && $this->groupId !== '') {
            $this->expanded = array_values(array_unique([...$this->expanded, $this->groupId]));
            $this->dispatch('assign-scroll-to', id: $this->groupId);
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'ranking';
    }

    /**
     * Abre o cierra el ranking completo de un grupo en la vista por materia.
     */
    public function toggleRanking(string $groupId, AssignmentBoardQuery $query): void
    {
        $group = $this->groupOrFail($groupId, $query);

        if (in_array($group->id, $this->expanded, true)) {
            $this->expanded = array_values(array_diff($this->expanded, [$group->id]));

            if ($this->pending !== null && $this->pending['group'] === $group->id && $this->pending['from'] === 'ranking') {
                $this->pending = null;
            }

            return;
        }

        $this->expanded[] = $group->id;
    }

    /**
     * Elige un grupo en los dos paneles; en pantallas angostas lo abre como hoja.
     */
    public function pick(string $groupId, AssignmentBoardQuery $query): void
    {
        $this->groupId = $this->groupOrFail($groupId, $query)->id;
        $this->tab = 'ranking';
        $this->sheet = true;
        $this->pending = $this->failure = null;
        $this->dispatch('assign-group-opened');
    }

    public function closeSheet(): void
    {
        $this->sheet = false;
    }

    /**
     * Asigna al docente si ocupa el primer lugar del ranking del grupo; si no, abre el motivo (HC-16).
     */
    public function assign(string $groupId, string $teacherId, AssignmentBoardQuery $query, GroupRankingQuery $rankings, AssignTeacher $action, string $from = 'ranking'): void
    {
        $group = $this->assignableGroup($groupId, $query);
        $teacher = $query->teacherOrFail($teacherId);
        $from = $from === 'tarjetas' ? 'tarjetas' : 'ranking';

        $this->pending = $this->failure = null;
        $this->reason = '';
        $this->resetErrorBag();

        [$position, $size] = $rankings->place($group, $teacher->id);

        if ($position === 1) {
            $this->run($group, $teacher, null, $action, $from);

            return;
        }

        $this->pending = ['group' => $group->id, 'teacher' => $teacher->id, 'name' => $teacher->name, 'position' => $position, 'size' => $size, 'from' => $from];
        $this->dispatch('assign-reason-opened');
    }

    /**
     * Asigna con el motivo escrito (HC-16).
     */
    public function confirmAssign(AssignmentBoardQuery $query, AssignTeacher $action): void
    {
        abort_if($this->pending === null, 404);

        $this->validate(
            ['reason' => ['required', 'string']],
            ['reason.required' => 'Escribe por qué no eliges al primer lugar.'],
        );

        $group = $this->assignableGroup($this->pending['group'], $query);
        $teacher = $query->teacherOrFail($this->pending['teacher']);

        $this->run($group, $teacher, $this->reason, $action, $this->pending['from']);
    }

    public function cancelReason(): void
    {
        $this->pending = null;
        $this->reason = '';
        $this->resetErrorBag();
    }

    /**
     * Pasa la carga del departamento de propuesta a asignación docente; si se veta, deja los motivos a la vista.
     */
    public function startStaffing(AssignmentBoardQuery $query, StartStaffing $action): void
    {
        abort_if($this->departmentId === null, 403);

        $load = $query->load((string) $this->termId, (string) $this->campusId, $this->departmentId);

        abort_if($load === null, 404);
        $this->authorize('startStaffing', $load);

        try {
            $action->handle($load, auth()->user());
        } catch (ValidationException $e) {
            $this->vetoReasons = collect($e->errors())->flatten()->map(fn ($reason): string => (string) $reason)->values()->all();
            $this->error('No pasó a asignación', e($this->vetoReasons[0] ?? ''));

            return;
        }

        $this->vetoReasons = [];
        $this->success('La carga pasó a Asignación', e('Ya puedes asignar los docentes de los grupos.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function with(AssignmentBoardQuery $query, GroupRankingQuery $rankings): array
    {
        if ($this->departmentId === null) {
            return ['board' => null];
        }

        $board = $this->board($query);
        $visible = $this->filtered($board->groups);
        $selected = $this->view === 'paneles' ? $this->selected($visible) : null;
        $visibleIds = array_map(fn (BoardGroup $row): string => $row->group->id, $visible);
        $openIds = $this->view === 'paneles'
            ? ($selected === null ? [] : [$selected->group->id])
            : array_values(array_intersect($this->expanded, $visibleIds));
        $load = $board->load;
        $assignable = $load !== null && in_array($load->status, [LoadStatus::Staffing, LoadStatus::Adjusting], true);

        return [
            'board' => $board,
            'visible' => $visible,
            'sections' => $this->view === 'materia' ? $this->sections($board, $visible) : [],
            'selected' => $selected,
            'rankings' => $rankings->forGroups($board->term, $openIds),
            'canAssign' => $assignable && Gate::allows('create', [GroupTeacherAssignment::class, $this->scopeGroup()]),
            'canStart' => $load?->status === LoadStatus::Proposed && Gate::allows('startStaffing', $load),
            'stateMessage' => $this->stateMessage($board),
            'counts' => ['todos' => $board->total(), 'sin' => $board->total() - $board->assignedCount(), 'con' => $board->assignedCount()],
            'subjects' => $this->subjectOptions($board),
            'careers' => $this->careerOptions($board),
            'semesters' => $this->semesterOptions($board),
            'polling' => collect($visible)->contains(fn (BoardGroup $row): bool => $row->rankingState === RankingState::Pending),
        ];
    }

    /**
     * Resuelve con la credencial el periodo, el campus y el departamento, y deja en propiedades bloqueadas con qué
     * pinta y escribe la página (o por qué no hay tablero). Un departamento pedido por la URL que el usuario no ve es
     * 403.
     */
    private function resolve(Actor $context, AssignmentBoardQuery $query): void
    {
        $this->termId = $this->campusId = $this->departmentId = null;
        $this->departments = [];

        $campus = $context->activeCampus();

        if ($campus === null) {
            $this->message = 'Elige un campus en tu credencial para ver la asignación docente.';

            return;
        }

        $term = $context->activeTerm();

        if ($term === null) {
            $this->message = 'No hay un periodo activo: elige uno en tu credencial.';

            return;
        }

        $departments = $query->departmentsFor($context, $campus);
        $department = $this->department === '' ? $departments->first() : $departments->first(fn (Department $department): bool => $department->code === $this->department);

        abort_if($this->department !== '' && $department === null, 403);

        $this->departments = $departments->map(fn (Department $department): array => ['code' => $department->code, 'name' => "{$department->code} · {$department->name}"])->all();

        if ($department === null) {
            $this->message = 'No tienes un departamento con asignación docente en este campus.';

            return;
        }

        $this->department = $department->code;
        $this->termId = $term->getKey();
        $this->campusId = $campus->getKey();
        $this->departmentId = $department->getKey();
        $this->message = null;
    }

    /**
     * `?grupo=` al entrar: en la vista por materia abre su ranking y lleva la página a él; en los dos paneles lo elige
     * y, en pantallas angostas, lo abre como hoja. Un grupo que no es del tablero se ignora.
     */
    private function openRequestedGroup(AssignmentBoardQuery $query): void
    {
        if ($this->groupId === '') {
            return;
        }

        $group = $this->departmentId === null ? null : $query->findGroup((string) $this->termId, (string) $this->campusId, $this->departmentId, $this->groupId);

        if ($group === null) {
            $this->groupId = '';

            return;
        }

        if ($this->view === 'materia') {
            $this->expanded = [$group->id];
            $this->scrollTo = $group->id;
        } else {
            $this->sheet = true;
        }
    }

    /**
     * El tablero con el que entró la página, con la policy de ver el ranking: una petición puede llegar de una página
     * que abrió otro usuario.
     */
    private function board(AssignmentBoardQuery $query): AssignmentBoard
    {
        abort_if($this->departmentId === null, 403);
        $this->authorize('viewAny', [StaffingRanking::class, $this->scopeGroup()]);

        return $query->board((string) $this->termId, (string) $this->campusId, $this->departmentId);
    }

    /**
     * Un grupo del tablero por un id del cliente, con la policy de ver el ranking; 404 si no es del tablero.
     */
    private function groupOrFail(string $groupId, AssignmentBoardQuery $query): Group
    {
        abort_if($this->departmentId === null, 403);
        $this->authorize('viewAny', [StaffingRanking::class, $this->scopeGroup()]);

        return $query->groupOrFail((string) $this->termId, (string) $this->campusId, $this->departmentId, $groupId);
    }

    /**
     * Un grupo del tablero con la policy de asignarle docente.
     */
    private function assignableGroup(string $groupId, AssignmentBoardQuery $query): Group
    {
        $group = $this->groupOrFail($groupId, $query);
        $this->authorize('create', [GroupTeacherAssignment::class, $group]);

        return $group;
    }

    /**
     * El alcance de la página como un grupo sin guardar (su campus y su departamento), para las policies de Staffing.
     */
    private function scopeGroup(): Group
    {
        return new Group(['campus_id' => $this->campusId, 'department_id' => $this->departmentId]);
    }

    private function run(Group $group, Teacher $teacher, ?string $reason, AssignTeacher $action, string $from): void
    {
        try {
            $assignment = $action->handle($group, $teacher, auth()->user(), $reason);
        } catch (ValidationException $e) {
            $message = (string) collect($e->errors())->flatten()->first();

            if ($this->pending !== null) {
                foreach ($e->errors() as $field => $messages) {
                    $this->addError($field === 'reason' ? 'reason' : 'assignment', (string) $messages[0]);
                }
            } else {
                $this->failure = ['group' => $group->id, 'teacher' => $teacher->id, 'from' => $from, 'message' => $message];
            }

            $this->error('No se asignó', e($message));

            return;
        }

        $group->loadMissing('subject');
        $place = $assignment->ranking_position_at_assignment === null
            ? 'Fuera del ranking'
            : "Lugar {$assignment->ranking_position_at_assignment} de {$assignment->ranking_size_at_assignment}";

        $this->pending = $this->failure = null;
        $this->reason = '';
        $this->expanded = array_values(array_diff($this->expanded, [$group->id]));
        $this->success(e("Asignaste a {$teacher->name}"), e("{$group->code} · {$group->subject->name}. {$place}."));
    }

    /**
     * Los grupos que cumplen los filtros: estado, materia, carrera, semestre y la búsqueda (clave, materia, carrera o
     * docente, sin importar acentos ni mayúsculas).
     *
     * @param  list<BoardGroup>  $groups
     * @return list<BoardGroup>
     */
    private function filtered(array $groups): array
    {
        $needle = self::fold(trim($this->search));

        return array_values(array_filter($groups, function (BoardGroup $row) use ($needle): bool {
            $group = $row->group;

            return match (true) {
                $this->state === 'sin' && $row->hasTeacher(), $this->state === 'con' && ! $row->hasTeacher() => false,
                $this->subjectFilter !== '' && $group->subject?->code !== $this->subjectFilter => false,
                $this->careerFilter !== '' && $group->ownerCareer?->code !== $this->careerFilter => false,
                $this->semesterFilter !== '' && (string) $group->semester !== $this->semesterFilter => false,
                $needle !== '' => str_contains(self::fold(implode(' ', [$group->code, $group->subject?->code, $group->subject?->name, $group->ownerCareer?->code, $group->teacher?->name])), $needle),
                default => true,
            };
        }));
    }

    /**
     * El grupo abierto en los dos paneles: el elegido si está a la vista; si no, el primero sin docente o el primero.
     *
     * @param  list<BoardGroup>  $visible
     */
    private function selected(array $visible): ?BoardGroup
    {
        foreach ($visible as $row) {
            if ($row->group->id === $this->groupId) {
                return $row;
            }
        }

        foreach ($visible as $row) {
            if (! $row->hasTeacher()) {
                return $row;
            }
        }

        return $visible[0] ?? null;
    }

    /**
     * Las materias de la vista por materia, en el orden del tablero, con sus grupos a la vista y su avance (de todos
     * sus grupos, no solo los filtrados).
     *
     * @param  list<BoardGroup>  $visible
     * @return list<array{subject: mixed, semester: int, total: int, assigned: int, dots: list<bool>, rows: list<BoardGroup>}>
     */
    private function sections(AssignmentBoard $board, array $visible): array
    {
        $all = collect($board->groups)->groupBy(fn (BoardGroup $row): string => $row->group->subject_id);
        $sections = [];

        foreach ($visible as $row) {
            $subjectId = $row->group->subject_id;

            if (! isset($sections[$subjectId])) {
                $groups = $all->get($subjectId);
                $sections[$subjectId] = [
                    'subject' => $row->group->subject,
                    'semester' => (int) $groups->min(fn (BoardGroup $other): int => $other->group->semester),
                    'total' => $groups->count(),
                    'assigned' => $groups->filter(fn (BoardGroup $other): bool => $other->hasTeacher())->count(),
                    'dots' => $groups->map(fn (BoardGroup $other): bool => $other->hasTeacher())->values()->all(),
                    'rows' => [],
                ];
            }

            $sections[$subjectId]['rows'][] = $row;
        }

        return array_values($sections);
    }

    /**
     * Lo que dice la página sobre el estado de la carga cuando no se asigna; null en asignación o ajustes.
     */
    private function stateMessage(AssignmentBoard $board): ?string
    {
        $load = $board->load;

        return match (true) {
            $load === null => "{$board->department->code} no tiene carga en {$board->campus->code} para este periodo.",
            $load->status === LoadStatus::Proposed => 'La carga está en Propuesta. Los docentes se asignan cuando pasa a Asignación.',
            $load->status === LoadStatus::Draft => 'La carga está en Borrador: los docentes se asignan cuando pasa a Asignación.',
            in_array($load->status, [LoadStatus::Staffing, LoadStatus::Adjusting], true) => null,
            default => "La carga está en {$load->status->label()}: ya no se asignan docentes.",
        };
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function subjectOptions(AssignmentBoard $board): array
    {
        return collect($board->groups)
            ->map(fn (BoardGroup $row): array => ['id' => (string) $row->group->subject?->code, 'name' => (string) $row->group->subject?->name])
            ->unique('id')
            ->sortBy(fn (array $option): string => self::fold($option['name']))
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function careerOptions(AssignmentBoard $board): array
    {
        return collect($board->groups)
            ->map(fn (BoardGroup $row): string => (string) $row->group->ownerCareer?->code)
            ->unique()
            ->sort()
            ->map(fn (string $code): array => ['id' => $code, 'name' => $code])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function semesterOptions(AssignmentBoard $board): array
    {
        return collect($board->groups)
            ->map(fn (BoardGroup $row): int => $row->group->semester)
            ->unique()
            ->sort()
            ->map(fn (int $semester): array => ['id' => (string) $semester, 'name' => "Semestre {$semester}"])
            ->values()
            ->all();
    }

    private static function fold(string $text): string
    {
        return Str::lower(Str::ascii($text));
    }
};
