<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Scheduling\Actions\Sessions\AssignSessionRoom;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Actions\AssignTeacher;
use App\Modules\Staffing\Actions\Selection\PickSelectionGroup;
use App\Modules\Staffing\Actions\Selection\ReleaseSelectionGroup;
use App\Modules\Staffing\Actions\UnassignTeacher;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Queries\SelectionOrderQuery;
use App\Modules\Staffing\Queries\SelectionRoomOptionsQuery;
use App\Modules\Staffing\Queries\SelectionTimetableQuery;
use App\Modules\Staffing\Queries\SelectionTurnQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Livewire;
use Mary\Traits\Toast;

/**
 * El drawer de un grupo en el horario de la orden de selección (tarea 1.33, diseño 6.4, 6.5 y 6.7; maqueta B, drawer
 * del grupo y «Asignar a…»). Se abre con `open-selection-group` desde la ficha, aparte de la página para que el
 * refresco de cada 5 s no lo vuelva a pintar. El docente sale siempre del usuario de la sesión, nunca del navegador.
 *
 * - **Grupo** (`mode = group`): sus datos, quién lo tiene, si está fuera de su experiencia académica o le choca y, en su turno, cuántas
 *   horas llevaría. Si es su turno y el grupo está libre, elige el salón entre los elegibles (SelectionRoomOptionsQuery,
 *   SEL-01 a SEL-04) en las pestañas Lista y Mapa y lo toma (PickSelectionGroup); si no queda ningún salón elegible lo
 *   toma sin salón. En el Mapa va `scheduling::components.map-picker` en modo «elegir» si ya existe (tarea 1.33, Task
 *   14); al tocar un salón elegible el mapa avisa `room-picked` con su `map_key` y aquí se elige ese salón
 *   (chooseRoomByMapKey). Los grupos que tomó en su turno los suelta (ReleaseSelectionGroup). Fuera de su turno es de
 *   solo lectura. Lo que rechace la Action (otro lo tomó antes: «Este grupo ya fue asignado.»; no es su turno) se dice en el drawer.
 * - **Asignar a…** (`mode = assign`), para quien tiene `staffing.teacher.assign` sobre el grupo (el jefe y el JPD), a
 *   cualquier hora: un docente de la cola, con lo que lleva y si le choca, y el salón entre los elegibles para quien
 *   también asigna salones (GroupPolicy::assignRoom); escribe con AssignTeacher y AssignSessionRoom.
 * - **Quitar docente** (`mode = unassign`, diseño 6.7), para los mismos: pide el motivo y escribe con UnassignTeacher,
 *   que deja el historial y la bitácora; el grupo queda libre y sus salones no cambian.
 *
 * También abre los grupos de una selección ya terminada (decisión 8: lo que siga sin resolver lo asignan a mano el jefe
 * o el JPD): para el docente es solo consulta; para la jefatura, «Asignar a…» y «Quitar docente» (ya terminada,
 * AssignTeacher sigue sin pedir el motivo de HC-16; HC-08 es aviso desde la tarea 1.50).
 *
 * Al tomar, soltar, asignar o quitar avisa `selection-changed` para que la página se vuelva a pintar.
 */
new class extends Component
{
    use Toast;

    #[Locked]
    public string $selectionId = '';

    #[Locked]
    public ?string $groupId = null;

    public bool $show = false;

    /** `group` (ver, tomar o soltar), `assign` («Asignar a…» de la jefatura) o `unassign` («Quitar docente»); solo decide qué se pinta. */
    public string $mode = 'group';

    /** La pestaña del salón: `lista` o `mapa`. */
    public string $roomTab = 'lista';

    public ?string $roomId = null;

    public ?string $teacherId = null;

    public string $teacherSearch = '';

    public string $reason = '';

    /** El motivo de «Quitar docente», obligatorio (UnassignTeacher). */
    public string $unassignReason = '';

    public function mount(string $selectionId, SelectionTimetableQuery $timetable): void
    {
        $this->selectionId = $selectionId;
        $this->selection($timetable);
    }

    #[On('open-selection-group')]
    public function open(string $id, Actor $context, SelectionTimetableQuery $timetable, SelectionTurnQuery $turns, SelectionRoomOptionsQuery $rooms): void
    {
        $selection = $this->selection($timetable);
        $group = $timetable->group($selection, $id) ?? abort(404);

        $this->resetValidation();
        $this->reset('teacherId', 'teacherSearch', 'reason', 'unassignReason', 'roomTab', 'roomId');
        $this->groupId = $group->getKey();
        $picking = $this->inTurn($selection, $context, $timetable, $turns) && $group->teacher_id === null;
        $this->mode = ! $picking && Gate::allows('create', [GroupTeacherAssignment::class, $group]) ? 'assign' : 'group';
        $this->roomId = $picking ? ($rooms->forGroup($selection, $group)['eligible'][0]['id'] ?? null) : null;
        // SEL-05: el id de quien lo tiene viaja en el estado de Livewire solo si quien abre asigna.
        $this->teacherId = $this->mode === 'assign' ? $group->teacher_id : null;
        $this->show = true;
    }

    /**
     * El salón que tocó en el mapa (`room-picked`): solo si es de los elegibles; si no, se queda el de antes.
     */
    public function chooseRoomByMapKey(string $key, SelectionTimetableQuery $timetable, SelectionRoomOptionsQuery $rooms): void
    {
        $group = $this->group($timetable);
        $room = collect($rooms->forGroup($this->selection($timetable), $group)['eligible'])->first(fn (array $option): bool => $option['map_key'] !== null && $option['map_key'] === $key);

        if ($room !== null) {
            $this->roomId = $room['id'];
        }
    }

    public function take(Actor $context, SelectionTimetableQuery $timetable, PickSelectionGroup $action): void
    {
        $user = $context->user() ?? abort(403);
        $selection = $this->selection($timetable);
        $group = $this->group($timetable);
        $this->authorize('pickOwn', $selection);
        $teacher = $timetable->activeTeacherOf($user) ?? abort(403);
        $room = $this->roomId === null ? null : $timetable->room($this->roomId);
        $this->resetValidation();

        try {
            $action->handle($selection, $teacher, $group, $room, $user);
        } catch (ValidationException $e) {
            $this->fail($e, 'No se tomó el grupo');

            return;
        }

        $this->show = false;
        $this->success('Tomaste '.self::label($group), $room === null ? 'Sin salón: lo asigna la jefatura.' : "En el {$room->code}.");
        $this->dispatch('selection-changed');
    }

    public function release(Actor $context, SelectionTimetableQuery $timetable, ReleaseSelectionGroup $action): void
    {
        $user = $context->user() ?? abort(403);
        $selection = $this->selection($timetable);
        $group = $this->group($timetable);
        $this->authorize('pickOwn', $selection);
        $teacher = $timetable->activeTeacherOf($user) ?? abort(403);
        $this->resetValidation();

        try {
            $action->handle($selection, $teacher, $group, $user);
        } catch (ValidationException $e) {
            $this->fail($e, 'No se soltó el grupo');

            return;
        }

        $this->show = false;
        $this->success('Soltaste '.self::label($group), 'Vuelve a quedar libre.');
        $this->dispatch('selection-changed');
    }

    /**
     * «Asignar a…»: la jefatura asigna a un docente de la cola y, si también asigna salones, le pone a todas las sesiones
     * no virtuales el salón que elija, sin el filtro de la selección (diseño 6.14): cualquiera activo del campus del
     * grupo, del departamento o sin dueño (SelectionRoomOptionsQuery::forManager). AssignSessionRoom aplica las reglas
     * de hoy: HC-02 lo rechaza; HC-05, HC-06 y HC-11 lo guardan y se dicen como aviso. Con el mismo docente solo cambia
     * el salón. Si el docente se asignó y el salón no, lo dice y deja el drawer abierto.
     */
    public function assign(Actor $context, SelectionTimetableQuery $timetable, SelectionRoomOptionsQuery $rooms, AssignTeacher $assignTeacher, AssignSessionRoom $assignRoom): void
    {
        $user = $context->user() ?? abort(403);
        $selection = $this->selection($timetable);
        $group = $this->group($timetable);
        $this->authorize('create', [GroupTeacherAssignment::class, $group]);
        $this->resetValidation();

        $changesTeacher = $this->teacherId !== null && $this->teacherId !== $group->teacher_id;
        $teacher = $changesTeacher ? $timetable->queueTeacher($selection, $this->teacherId) : null;

        if ($changesTeacher && $teacher === null) {
            $this->addError('teacherId', 'Elige un docente del orden de selección.');

            return;
        }

        $room = null;

        if ($this->roomId !== null && $this->roomId !== '' && Gate::allows('assignRoom', $group)) {
            $manager = $rooms->forManager($selection, $group);
            $offered = array_column([...$manager['recommended'], ...$manager['others']], 'id');
            $room = in_array($this->roomId, $offered, true) ? $timetable->room($this->roomId) : null;

            if ($room === null) {
                $this->addError('roomId', 'Ese salón no es del campus del grupo ni del departamento.');

                return;
            }
        }

        if (! $changesTeacher && $room === null) {
            $this->addError('teacherId', 'Elige otro docente o un salón.');

            return;
        }

        if ($changesTeacher) {
            try {
                $assignTeacher->handle($group, $teacher, $user, filled($this->reason) ? $this->reason : null);
            } catch (ValidationException $e) {
                $this->fail($e, 'No se asignó el docente');

                return;
            }

            $this->dispatch('selection-changed');
        }

        if ($room !== null) {
            try {
                $assignRoom->handleMany($group, array_fill_keys($timetable->roomSessionIds($group), $room), $user);
            } catch (ValidationException $e) {
                $this->fail($e, $changesTeacher ? "Se asignó a {$teacher->name}, pero no el salón" : 'No se cambió el salón', 'roomId');

                return;
            }

            $this->dispatch('selection-changed');
        }

        $this->show = false;
        $title = $changesTeacher ? 'Asignaste '.self::label($group)." a {$teacher->name}" : 'Cambiaste el salón de '.self::label($group);
        $warnings = $room === null ? [] : $timetable->roomWarnings($group);

        $warnings === []
            ? $this->success($title, $room === null ? null : "En el {$room->code}.")
            : $this->warning($title, e("En el {$room->code}, con aviso: ".implode(' ', $warnings)));
    }

    /**
     * «Quitar docente»: pasa a pedir el motivo, solo para quien asigna y si el grupo tiene docente.
     */
    public function confirmUnassign(SelectionTimetableQuery $timetable): void
    {
        $group = $this->group($timetable);
        $this->authorize('create', [GroupTeacherAssignment::class, $group]);
        $this->resetValidation();
        $this->reset('unassignReason');
        $this->mode = $group->teacher_id === null ? 'assign' : 'unassign';
    }

    /**
     * Quita al docente del grupo con UnassignTeacher (motivo obligatorio, historial y bitácora). Lo que rechace se dice en
     * el drawer, que se queda abierto.
     */
    public function unassign(Actor $context, SelectionTimetableQuery $timetable, UnassignTeacher $action): void
    {
        $user = $context->user() ?? abort(403);
        $group = $this->group($timetable);
        $this->authorize('create', [GroupTeacherAssignment::class, $group]);
        $this->resetValidation();
        $previous = $group->loadMissing(['teacher' => fn ($teacher) => $teacher->withTrashed()->select(['id', 'name'])])->teacher?->name;
        $this->unassignReason = trim($this->unassignReason);

        $this->validate(
            ['unassignReason' => ['required', 'string', 'max:500']],
            ['unassignReason.required' => 'Escribe el motivo para quitar al docente.'],
            ['unassignReason' => 'motivo'],
        );

        try {
            $action->handle($group, $user, $this->unassignReason);
        } catch (ValidationException $e) {
            $this->fail($e, 'No se quitó el docente', 'unassignReason');

            return;
        }

        $this->show = false;
        $this->success('Quitaste a '.($previous ?? 'el docente').' de '.self::label($group), 'El grupo queda libre.');
        $this->dispatch('selection-changed');
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Actor $context, SelectionTimetableQuery $timetable, SelectionTurnQuery $turns, SelectionRoomOptionsQuery $rooms, SelectionOrderQuery $order): array
    {
        $selection = $this->selection($timetable);
        $group = $this->show && $this->groupId !== null ? $timetable->group($selection, $this->groupId) : null;

        if ($group === null) {
            return ['card' => null, 'gone' => $this->show && $this->groupId !== null];
        }

        $user = $context->user() ?? abort(403);
        $teacher = $timetable->activeTeacherOf($user);
        $entry = $teacher === null ? null : $timetable->entryOf($selection, $teacher);
        $canAssign = Gate::allows('create', [GroupTeacherAssignment::class, $group]);
        // SEL-05: solo quien asigna ve el nombre de quien lo tiene; el docente ve «Asignado».
        $card = $timetable->card($selection, $group, $entry === null ? null : $teacher, $canAssign);
        $inTurn = $entry !== null && $turns->isTurnOf($selection, $teacher, CarbonImmutable::now()) && Gate::allows('pickOwn', $selection);
        $pickable = $inTurn && ! $card['taken'] && ! $card['clashes'];
        $releasable = $inTurn && $card['mine'];
        $unassigning = $this->mode === 'unassign' && $canAssign && $card['taken'];
        $assigning = ($this->mode === 'assign' || ($this->mode === 'unassign' && ! $unassigning)) && $canAssign;
        $canAssignRoom = $assigning && Gate::allows('assignRoom', $group);
        $options = $pickable ? $rooms->forGroup($selection, $group) : ['eligible' => [], 'rejected' => []];
        $managerRooms = $canAssignRoom ? $rooms->forManager($selection, $group) : ['recommended' => [], 'others' => []];
        $changesTeacher = $this->teacherId !== null && $this->teacherId !== $card['teacher_id'];
        $changesRoom = $canAssignRoom && $this->roomId !== null && $this->roomId !== '';
        $candidates = $assigning ? $timetable->candidates($selection, $group, $order->forSelection($selection)) : [];
        $needle = mb_strtolower(trim($this->teacherSearch));

        return [
            'card' => $card,
            'gone' => false,
            'selection' => $selection,
            'entry' => $entry,
            'hours' => $entry === null ? null : $timetable->hoursOf($selection, $entry),
            'inTurn' => $inTurn,
            'pickable' => $pickable,
            'releasable' => $releasable,
            'canAssign' => $canAssign,
            'assigning' => $assigning,
            'unassigning' => $unassigning,
            'finished' => $selection->status === SelectionStatus::Finished,
            'canAssignRoom' => $canAssignRoom,
            'options' => $options,
            'managerRooms' => $managerRooms,
            'changesTeacher' => $changesTeacher,
            'changesRoom' => $changesRoom,
            'chosen' => collect($options['eligible'])->firstWhere('id', $this->roomId),
            'candidates' => $needle === '' ? $candidates : array_values(array_filter($candidates, fn (array $row): bool => str_contains(mb_strtolower($row['name']), $needle))),
            'chosenTeacher' => collect($candidates)->firstWhere('teacher_id', $this->teacherId),
            'mySlot' => $entry === null ? null : ($selection->status === SelectionStatus::ExtraRound ? $entry->extra_slot_starts_at : $entry->slot_starts_at)?->setTimezone(config('app.timezone')),
            'mapPicker' => $pickable && $this->roomTab === 'mapa' && Livewire::exists('scheduling::components.map-picker'),
        ];
    }

    /**
     * Lo que rechazó la Action, en su campo y en un aviso.
     */
    private function fail(ValidationException $e, string $title, ?string $field = null): void
    {
        foreach ($e->errors() as $key => $messages) {
            $this->addError($field ?? $key, $messages[0]);
        }

        $this->error($title, e(collect($e->errors())->flatten()->first()));
    }

    /**
     * Si es el turno del docente de la sesión.
     */
    private function inTurn(Selection $selection, Actor $context, SelectionTimetableQuery $timetable, SelectionTurnQuery $turns): bool
    {
        $user = $context->user();
        $teacher = $user === null ? null : $timetable->activeTeacherOf($user);

        return $teacher !== null && $turns->isTurnOf($selection, $teacher, CarbonImmutable::now());
    }

    /**
     * La selección del drawer, viva o terminada, para quien la ve (SelectionPolicy::view); 404 si ya no se muestra (un
     * borrador o una cancelada), 403 si no la ve.
     */
    private function selection(SelectionTimetableQuery $timetable): Selection
    {
        $selection = $timetable->shownSelection($this->selectionId) ?? abort(404);
        $this->authorize('view', $selection);

        return $selection;
    }

    private function group(SelectionTimetableQuery $timetable): Group
    {
        return $this->groupId === null ? abort(404) : ($timetable->group($this->selection($timetable), $this->groupId) ?? abort(404));
    }

    private static function label(Group $group): string
    {
        $group->loadMissing('subject:id,code');

        return trim("{$group->subject?->code} {$group->code}");
    }
};
