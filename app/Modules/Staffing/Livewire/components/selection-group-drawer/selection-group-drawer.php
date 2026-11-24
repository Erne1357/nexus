<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Scheduling\Actions\Sessions\AssignSessionRoom;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Actions\AssignTeacher;
use App\Modules\Staffing\Actions\Selection\PickSelectionGroup;
use App\Modules\Staffing\Actions\Selection\ReleaseSelectionGroup;
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
 * - **Grupo** (`mode = group`): sus datos, quién lo tiene, si está fuera de su perfil o le choca y, en su turno, cuántas
 *   horas llevaría. Si es su turno y el grupo está libre, elige el salón entre los elegibles (SelectionRoomOptionsQuery,
 *   SEL-01 a SEL-04) en las pestañas Lista y Mapa y lo toma (PickSelectionGroup); si no queda ningún salón elegible lo
 *   toma sin salón. En el Mapa va `scheduling::components.map-picker` en modo «elegir» si ya existe (tarea 1.33, Task
 *   14); al tocar un salón elegible el mapa avisa `room-picked` con su `map_key` y aquí se elige ese salón
 *   (chooseRoomByMapKey). Los grupos que tomó en su turno los suelta (ReleaseSelectionGroup). Fuera de su turno es de
 *   solo lectura. Lo que rechace la Action (otro lo tomó antes: «Ya lo tomó X»; no es su turno) se dice en el drawer.
 * - **Asignar a…** (`mode = assign`), para quien tiene `staffing.teacher.assign` sobre el grupo (el jefe y el JPD), a
 *   cualquier hora: un docente de la cola, con lo que lleva y si le choca, y el salón entre los elegibles para quien
 *   también asigna salones (GroupPolicy::assignRoom); escribe con AssignTeacher y AssignSessionRoom.
 *
 * Al tomar, soltar o asignar avisa `selection-changed` para que la página se vuelva a pintar.
 */
new class extends Component
{
    use Toast;

    #[Locked]
    public string $selectionId = '';

    #[Locked]
    public ?string $groupId = null;

    public bool $show = false;

    /** `group` (ver, tomar o soltar) o `assign` («Asignar a…» de la jefatura); solo decide qué se pinta. */
    public string $mode = 'group';

    /** La pestaña del salón: `lista` o `mapa`. */
    public string $roomTab = 'lista';

    public ?string $roomId = null;

    public ?string $teacherId = null;

    public string $teacherSearch = '';

    public string $reason = '';

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
        $this->reset('teacherId', 'teacherSearch', 'reason', 'roomTab', 'roomId');
        $this->groupId = $group->getKey();
        $picking = $this->inTurn($selection, $context, $timetable, $turns) && $group->teacher_id === null;
        $this->mode = ! $picking && Gate::allows('create', [GroupTeacherAssignment::class, $group]) ? 'assign' : 'group';
        $this->roomId = $picking ? ($rooms->forGroup($selection, $group)['eligible'][0]['id'] ?? null) : null;
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
     * «Asignar a…»: la jefatura asigna a un docente de la cola y, si también asigna salones, le pone el salón elegido a
     * todas sus sesiones no virtuales. Si el docente se asignó y el salón no, lo dice y deja el drawer abierto.
     */
    public function assign(Actor $context, SelectionTimetableQuery $timetable, SelectionRoomOptionsQuery $rooms, AssignTeacher $assignTeacher, AssignSessionRoom $assignRoom): void
    {
        $user = $context->user() ?? abort(403);
        $selection = $this->selection($timetable);
        $group = $this->group($timetable);
        $this->authorize('create', [GroupTeacherAssignment::class, $group]);
        $this->resetValidation();

        $teacher = $this->teacherId === null ? null : $timetable->queueTeacher($selection, $this->teacherId);

        if ($teacher === null) {
            $this->addError('teacherId', 'Elige un docente del orden de selección.');

            return;
        }

        $room = null;

        if ($this->roomId !== null && $this->roomId !== '' && Gate::allows('assignRoom', $group)) {
            $eligible = array_column($rooms->forGroup($selection, $group)['eligible'], 'id');
            $room = in_array($this->roomId, $eligible, true) ? $timetable->room($this->roomId) : null;

            if ($room === null) {
                $this->addError('roomId', 'Ese salón ya no se puede elegir a esa hora.');

                return;
            }
        }

        try {
            $assignTeacher->handle($group, $teacher, $user, filled($this->reason) ? $this->reason : null);
        } catch (ValidationException $e) {
            $this->fail($e, 'No se asignó el docente');

            return;
        }

        $this->dispatch('selection-changed');

        if ($room !== null) {
            try {
                $assignRoom->handleMany($group, array_fill_keys($timetable->roomSessionIds($group), $room), $user);
            } catch (ValidationException $e) {
                $this->fail($e, "Se asignó a {$teacher->name}, pero no el salón", 'roomId');

                return;
            }
        }

        $this->show = false;
        $this->success('Asignaste '.self::label($group)." a {$teacher->name}", $room === null ? null : "En el {$room->code}.");
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
        $card = $timetable->card($selection, $group, $entry === null ? null : $teacher);
        $inTurn = $entry !== null && $turns->isTurnOf($selection, $teacher, CarbonImmutable::now()) && Gate::allows('pickOwn', $selection);
        $pickable = $inTurn && $card['teacher_id'] === null && ! $card['clashes'];
        $releasable = $inTurn && $card['mine'];
        $canAssign = Gate::allows('create', [GroupTeacherAssignment::class, $group]);
        $assigning = $this->mode === 'assign' && $canAssign;
        $canAssignRoom = $assigning && Gate::allows('assignRoom', $group);
        $options = $pickable || $canAssignRoom ? $rooms->forGroup($selection, $group) : ['eligible' => [], 'rejected' => []];
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
            'canAssignRoom' => $canAssignRoom,
            'options' => $options,
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
     * La selección viva del drawer, para quien la ve (SelectionPolicy::view); 404 si ya no está viva, 403 si no la ve.
     */
    private function selection(SelectionTimetableQuery $timetable): Selection
    {
        $selection = $timetable->liveSelection($this->selectionId) ?? abort(404);
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
