<?php

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Support\WeekGrid;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Actions\Sessions\PlaceGroupSession;
use App\Modules\Scheduling\Actions\Sessions\RemoveGroupSession;
use App\Modules\Scheduling\Actions\Sessions\UpdateGroupSession;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Queries\ScheduleEditorQuery;
use App\Modules\Scheduling\Rules\LoadEditability;
use App\Modules\Scheduling\Validation\Clock;
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Staffing\Actions\AssignTeacher;
use App\Modules\Staffing\Actions\UnassignTeacher;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Models\StaffingRanking;
use App\Modules\Staffing\Queries\AssignmentBoardQuery;
use App\Modules\Staffing\Queries\CurrentLoadQuery;
use App\Modules\Staffing\Queries\GroupRankingQuery;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * El cajón de un grupo de «Carga actual» (tarea 1.61, SEL-18; maqueta 1-carga-b, variante B): arriba el grupo, su materia
 * y los choques duros vigentes (los mismos de la lista, de solo lectura) y dos pestañas.
 *
 * «Sesiones y salones» reúne lo del editor de horarios con sus mismas Actions y reglas: cambiar día, inicio y fin
 * (UpdateGroupSession), agregar (PlaceGroupSession) y quitar una sesión (RemoveGroupSession) y elegir su salón con la
 * sección `scheduling::components.session-room` tal cual (AssignSessionRoom). El validador responde como en el editor:
 * lo que la Action rechaza (HC-01, HC-02, HC-03, HC-04…) se lee en el cajón y nada se guarda; los choques que deja la
 * sesión salen de ScheduleEditorQuery::sessionPanel. Edita quien puede GroupPolicy::editSessions (el jefe del
 * departamento) con la carga editable (LoadEditability); el JPD y los demás la ven de solo lectura con su motivo.
 *
 * «Docente» asigna como Asignación docente (HC-16, HC-21): el primer lugar del ranking del grupo se asigna sin motivo;
 * otro del ranking o uno buscado con `staffing::components.teacher-search` pide el motivo (AssignTeacher); «Quitar»
 * pide motivo (UnassignTeacher). Asigna quien puede GroupTeacherAssignmentPolicy::create (jefe y JPD) con la carga en
 * Asignación, Ajustes o Re-análisis.
 *
 * El grupo se busca con CurrentLoadQuery::groupOrFail (el departamento, periodo y modalidad de la página; 404 si es de
 * otro) y cada método vuelve a autorizar. Tras guardar avisa a la página con `current-load-changed` para que refresque
 * la fila.
 */
new class extends Component
{
    use Toast;

    #[Locked]
    public string $termId = '';

    #[Locked]
    public string $modalityId = '';

    #[Locked]
    public string $departmentId = '';

    public bool $show = false;

    #[Locked]
    public ?string $groupId = null;

    /** 'sesiones' o 'docente'. */
    public string $tab = 'sesiones';

    /** La sesión que se está editando y la que muestra su salón; null ninguna. */
    #[Locked]
    public ?string $editing = null;

    #[Locked]
    public ?string $roomFor = null;

    public string $day = '';

    public string $startsAt = '';

    public string $endsAt = '';

    public string $kind = '';

    public bool $adding = false;

    /** El motivo de asignar fuera del primer lugar (HC-16). */
    public string $reason = '';

    /** @var array{teacher: string, name: string, position: ?int, size: int}|null */
    #[Locked]
    public ?array $pending = null;

    public bool $unassigning = false;

    public string $unassignReason = '';

    public function mount(string $termId, string $modalityId, string $departmentId): void
    {
        $this->termId = $termId;
        $this->modalityId = $modalityId;
        $this->departmentId = $departmentId;
    }

    #[On('open-load-group')]
    public function open(string $id, CurrentLoadQuery $query): void
    {
        $group = $this->groupOrFail($query, $id);

        $this->groupId = $group->id;
        $this->tab = 'sesiones';
        $this->resetForms();
        $this->show = true;
    }

    /** La sección del salón asignó o quitó uno: la fila de la lista se refresca. */
    #[On('session-room-saved')]
    public function roomSaved(): void
    {
        if ($this->show) {
            $this->dispatch('current-load-changed');
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab === 'docente' ? 'docente' : 'sesiones';
    }

    /** Abre el formulario de una sesión con lo guardado. */
    public function edit(string $sessionId, CurrentLoadQuery $query): void
    {
        $session = $this->sessionOrFail($query, $sessionId);
        $this->resetForms();
        $this->editing = $session->id;
        $this->day = (string) $session->day;
        $this->startsAt = Clock::of($session->starts_at);
        $this->endsAt = Clock::of($session->ends_at);
        $this->kind = $session->kind->value;
    }

    public function cancelEdit(): void
    {
        $this->resetForms();
    }

    public function toggleRoom(string $sessionId, CurrentLoadQuery $query): void
    {
        $session = $this->sessionOrFail($query, $sessionId);
        $this->roomFor = $this->roomFor === $session->id ? null : $session->id;
        $this->editing = null;
        $this->adding = false;
    }

    public function startAdding(ScheduleEditorQuery $editor, CurrentLoadQuery $query): void
    {
        $group = $this->groupOrFail($query, (string) $this->groupId);
        $this->authorize('editSessions', $group);

        $this->resetForms();
        $this->adding = true;
        $this->kind = $editor->placement($group)['kind']->value;
        $grid = WeekGrid::of($group->modality()->withTrashed()->firstOrFail()->profile);
        $this->day = (string) ($grid->days[0]['day'] ?? 1);
        $this->startsAt = (string) ($grid->blocks[0]['starts_at'] ?? '');
        $this->endsAt = (string) ($grid->blocks[0]['ends_at'] ?? '');
    }

    /**
     * Guarda el día, el inicio y el fin de la sesión (UpdateGroupSession; HC-01 a HC-04 como en el editor).
     */
    public function saveSession(UpdateGroupSession $update, CurrentLoadQuery $query, Actor $context): void
    {
        $session = $this->sessionOrFail($query, (string) $this->editing);
        $this->authorize('editSessions', $session->group);
        $this->resetErrorBag();

        $this->validateTimes($session->group, $session);
        $room = $session->room?->code;

        try {
            $result = $update->handle($session, (int) $this->day, $this->startsAt, $this->endsAt, SessionKind::from($this->kind), $context->user());
        } catch (ValidationException $exception) {
            $this->rejected($exception);

            return;
        }

        $this->resetForms();
        $this->dispatch('current-load-changed');

        if ($result->roomDropped) {
            $this->warning('Sesión guardada sin salón', e($this->labelOf($result->session)." Quitamos el salón {$room}: estaba ocupado a esa hora."));
        } else {
            $this->success('Sesión guardada', e($this->labelOf($result->session)));
        }
    }

    /**
     * Agrega una sesión sin salón (PlaceGroupSession).
     */
    public function addSession(PlaceGroupSession $place, CurrentLoadQuery $query, Actor $context): void
    {
        $group = $this->groupOrFail($query, (string) $this->groupId);
        $this->authorize('editSessions', $group);
        $this->resetErrorBag();

        $this->validateTimes($group, null);

        try {
            $result = $place->handle($group, (int) $this->day, $this->startsAt, $this->endsAt, SessionKind::from($this->kind), $context->user());
        } catch (ValidationException $exception) {
            $this->rejected($exception);

            return;
        }

        $this->resetForms();
        $this->dispatch('current-load-changed');
        $this->success('Sesión agregada', e($this->labelOf($result->session)));
    }

    public function removeSession(string $sessionId, RemoveGroupSession $remove, CurrentLoadQuery $query, Actor $context): void
    {
        $session = $this->sessionOrFail($query, $sessionId);
        $this->authorize('editSessions', $session->group);
        $label = $this->labelOf($session);

        try {
            $remove->handle($session, $context->user());
        } catch (ValidationException $exception) {
            $this->rejected($exception, 'No se quitó');

            return;
        }

        $this->resetForms();
        $this->dispatch('current-load-changed');
        $this->success('Sesión quitada', e($label));
    }

    /**
     * Asigna al docente si ocupa el primer lugar del ranking del grupo; si no, pide el motivo (HC-16).
     */
    public function assign(string $teacherId, CurrentLoadQuery $query, AssignmentBoardQuery $board, GroupRankingQuery $rankings, AssignTeacher $action): void
    {
        $group = $this->assignableGroup($query);
        $teacher = $board->teacherOrFail($teacherId);

        $this->pending = null;
        $this->reason = '';
        $this->unassigning = false;
        $this->resetErrorBag();

        [$position, $size] = $rankings->place($group, $teacher->id);

        if ($position === 1) {
            $this->run($group, $teacher, null, $action);

            return;
        }

        $this->pending = ['teacher' => $teacher->id, 'name' => $teacher->name, 'position' => $position, 'size' => $size];
    }

    /** Un docente elegido en «Buscar otro docente» (HC-21): como cualquiera, con el motivo de HC-16. */
    #[On('teacher-picked')]
    public function pickSearched(string $groupId, string $teacherId, CurrentLoadQuery $query, AssignmentBoardQuery $board, GroupRankingQuery $rankings, AssignTeacher $action): void
    {
        abort_unless($groupId === $this->groupId, 404);

        $this->assign($teacherId, $query, $board, $rankings, $action);
    }

    public function confirmAssign(CurrentLoadQuery $query, AssignmentBoardQuery $board, AssignTeacher $action): void
    {
        abort_if($this->pending === null, 404);

        $this->validate(
            ['reason' => ['required', 'string']],
            ['reason.required' => 'Escribe por qué no eliges al primer lugar.'],
        );

        $group = $this->assignableGroup($query);
        $teacher = $board->teacherOrFail($this->pending['teacher']);

        $this->run($group, $teacher, $this->reason, $action);
    }

    public function cancelReason(): void
    {
        $this->pending = null;
        $this->reason = '';
        $this->resetErrorBag();
    }

    public function askUnassign(CurrentLoadQuery $query): void
    {
        $group = $this->assignableGroup($query);
        $this->pending = null;
        $this->resetErrorBag();

        abort_if($group->teacher_id === null, 404);

        $this->unassignReason = '';
        $this->unassigning = true;
    }

    public function unassign(CurrentLoadQuery $query, UnassignTeacher $action): void
    {
        $group = $this->assignableGroup($query);
        abort_unless($this->unassigning, 404);
        $name = (string) $group->teacher?->name;

        $this->validate(
            ['unassignReason' => ['required', 'string', 'max:500']],
            ['unassignReason.required' => 'Escribe el motivo para quitar al docente.'],
            ['unassignReason' => 'motivo'],
        );

        try {
            $action->handle($group, auth()->user(), $this->unassignReason);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field === 'reason' ? 'unassignReason' : 'unassign', (string) $messages[0]);
            }

            $this->error('No se quitó el docente', e((string) collect($e->errors())->flatten()->first()));

            return;
        }

        $this->unassigning = false;
        $this->unassignReason = '';
        $this->dispatch('current-load-changed');
        $this->success(e("Quitaste a {$name}"), e("{$group->code} queda sin docente."));
    }

    public function cancelUnassign(): void
    {
        $this->unassigning = false;
        $this->unassignReason = '';
        $this->resetErrorBag();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(CurrentLoadQuery $query, ScheduleEditorQuery $editor, GroupRankingQuery $rankings, Actor $context): array
    {
        if (! $this->show || $this->groupId === null) {
            return ['group' => null];
        }

        $group = $this->groupOrFail($query, $this->groupId);
        $group->load([
            'campus' => fn ($relation) => $relation->withTrashed(),
            'subject' => fn ($relation) => $relation->withTrashed(),
            'ownerCareer' => fn ($relation) => $relation->withTrashed(),
            'teacher' => fn ($relation) => $relation->withTrashed(),
            'sessions' => fn ($relation) => $relation->where('is_active', true)->orderBy('day')->orderBy('starts_at'),
            'sessions.room' => fn ($relation) => $relation->withTrashed(),
        ]);
        $gate = Gate::forUser(auth()->user());
        $term = $editor->term($this->termId);
        $grid = WeekGrid::of($group->modality()->withTrashed()->firstOrFail()->profile);

        $editReason = $this->editReason($group, $gate->allows('editSessions', $group));
        $assignReason = $this->assignReason($group, $gate->allows('create', [GroupTeacherAssignment::class, $group]));
        $panels = [];

        if ($group->campus_id !== null) {
            $campus = $editor->campus($group->campus_id);

            foreach ($group->sessions as $session) {
                $panels[$session->id] = $editor->sessionPanel($context, $term, $campus, $session->id);
            }
        }

        $canRank = $gate->allows('viewAny', [StaffingRanking::class, $group]);
        $ranking = $canRank ? ($rankings->forGroups($term, [$group->id])[$group->id] ?? null) : null;

        return [
            'group' => $group,
            'conflicts' => $query->conflictsOf($group),
            'rows' => $group->sessions->map(fn (GroupSession $session): array => [
                'id' => $session->id,
                'day' => ucfirst(Minutes::dayName($session->day)),
                'range' => WeekGrid::range(Clock::of($session->starts_at), Clock::of($session->ends_at)),
                'kind' => $session->kind->label(),
                'virtual' => $session->kind === SessionKind::Virtual,
                'room' => $session->room?->code,
                'updated_at' => (string) $session->updated_at?->getTimestamp(),
            ])->all(),
            'editReason' => $editReason,
            'canEdit' => $editReason === null,
            'panels' => $panels,
            'days' => array_map(fn (array $day): array => ['id' => (string) $day['day'], 'name' => $day['name']], $grid->days),
            'starts' => array_map(fn (array $block): array => ['id' => $block['starts_at'], 'name' => WeekGrid::clock($block['starts_at'])], $grid->blocks),
            'ends' => array_map(fn (array $block): array => ['id' => $block['ends_at'], 'name' => WeekGrid::clock($block['ends_at'])], $grid->blocks),
            'kinds' => [['id' => 'teoria', 'name' => 'Teoría'], ['id' => 'practica', 'name' => 'Práctica'], ['id' => 'virtual', 'name' => 'Virtual']],
            'assignReason' => $assignReason,
            'canAssign' => $assignReason === null,
            'canRank' => $canRank,
            'top' => $ranking === null ? [] : array_slice($ranking->eligible(), 0, 5),
        ];
    }

    /**
     * Por qué no se editan las sesiones: la carga que no lo acepta (LoadEditability) o quién sí las acomoda; null si sí.
     */
    private function editReason(Group $group, bool $allowed): ?string
    {
        if ($group->campus_id === null) {
            return 'Esta modalidad no usa salones: sus sesiones se ven aquí y se cambian en Horarios.';
        }

        $blocked = app(LoadEditability::class)->blockedReasons([$group])[$group->id];

        return $blocked ?? ($allowed ? null : 'Solo lectura: acomoda las sesiones el jefe del departamento.');
    }

    /**
     * Por qué no se asigna docente: quién sí asigna o el estado de la carga (igual que Asignación docente); null si sí.
     */
    private function assignReason(Group $group, bool $allowed): ?string
    {
        if (! $allowed) {
            return 'Solo lectura: asigna docentes el jefe del departamento y el jefe de proyecto docente.';
        }

        $load = AcademicLoad::query()->ofDepartment($group->term_id, $group->modality_id, $group->department_id)->first();

        return match (true) {
            $load === null => 'El departamento no tiene carga en este periodo.',
            $load->status->acceptsTeacherAssignment() => null,
            default => "La carga está en {$load->status->label()}: los docentes se asignan en Asignación, Ajustes o Re-análisis.",
        };
    }

    private function run(Group $group, Teacher $teacher, ?string $reason, AssignTeacher $action): void
    {
        try {
            $assignment = $action->handle($group, $teacher, auth()->user(), $reason);
        } catch (ValidationException $e) {
            $message = (string) collect($e->errors())->flatten()->first();

            foreach ($e->errors() as $field => $messages) {
                $this->addError($field === 'reason' ? 'reason' : 'assignment', (string) $messages[0]);
            }

            $this->error('No se asignó', e($message));

            return;
        }

        $place = $assignment->ranking_position_at_assignment === null
            ? 'Fuera del ranking'
            : "Lugar {$assignment->ranking_position_at_assignment} de {$assignment->ranking_size_at_assignment}";

        $this->pending = null;
        $this->reason = '';
        $this->dispatch('current-load-changed');
        $this->success(e("Asignaste a {$teacher->name}"), e("{$group->code}. {$place}."));
    }

    /**
     * Las horas del formulario contra los bloques del perfil de la modalidad (más las que ya tiene la sesión), como el
     * cajón de la sesión del editor.
     */
    private function validateTimes(Group $group, ?GroupSession $session): void
    {
        $grid = WeekGrid::of($group->modality()->withTrashed()->firstOrFail()->profile);

        $this->validate([
            'day' => ['required', Rule::in(array_map('strval', array_column($grid->days, 'day')))],
            'startsAt' => ['required', Rule::in([...array_column($grid->blocks, 'starts_at'), ...($session === null ? [] : [Clock::of($session->starts_at)])])],
            'endsAt' => ['required', Rule::in([...array_column($grid->blocks, 'ends_at'), ...($session === null ? [] : [Clock::of($session->ends_at)])])],
            'kind' => ['required', Rule::enum(SessionKind::class)],
        ], attributes: ['day' => 'día', 'startsAt' => 'inicio', 'endsAt' => 'fin', 'kind' => 'tipo']);
    }

    private function rejected(ValidationException $exception, string $title = 'No se guardó'): void
    {
        $message = (string) collect($exception->errors())->flatten()->first();
        $this->addError('session', $message);
        $this->error($title, e($message));
    }

    private function labelOf(GroupSession $session): string
    {
        $session->loadMissing(['group.subject' => fn ($query) => $query->withTrashed()]);

        return "{$session->group->subject->code} · {$session->group->code}: ".Minutes::dayName($session->day).' '.WeekGrid::range(Clock::of($session->starts_at), Clock::of($session->ends_at)).'.';
    }

    private function resetForms(): void
    {
        $this->reset('editing', 'roomFor', 'adding', 'day', 'startsAt', 'endsAt', 'kind', 'reason', 'pending', 'unassigning', 'unassignReason');
        $this->resetErrorBag();
    }

    private function groupOrFail(CurrentLoadQuery $query, string $id): Group
    {
        abort_unless(app(Actor::class)->allows(AcademicLoadPermission::LoadView, Target::of(modality: $this->modalityId, unit: $this->departmentId)), 403);

        return $query->groupOrFail($this->termId, $this->modalityId, $this->departmentId, $id);
    }

    private function assignableGroup(CurrentLoadQuery $query): Group
    {
        $group = $this->groupOrFail($query, (string) $this->groupId);
        $this->authorize('create', [GroupTeacherAssignment::class, $group]);

        return $group;
    }

    private function sessionOrFail(CurrentLoadQuery $query, string $sessionId): GroupSession
    {
        $group = $this->groupOrFail($query, (string) $this->groupId);

        return KeyLookup::findOrFail(
            GroupSession::query()->where('group_id', $group->id)->with(['group', 'room' => fn ($relation) => $relation->withTrashed()]),
            $sessionId,
        );
    }
};
