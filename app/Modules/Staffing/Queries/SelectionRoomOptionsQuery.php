<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Academic\Enums\RequiredSpace;
use App\Modules\Academic\Models\Subject;
use App\Modules\CatalogValidation\Queries\ConfirmedRoomsQuery;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\RoomBlock;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Rules\RoomEligibility;
use Illuminate\Database\Eloquent\Builder;

/**
 * Los salones que un docente puede elegir para un grupo durante la selección (tarea 1.33, SEL-01 a SEL-04).
 *
 * SEL-01: salones activos del campus del grupo, del departamento de la selección y confirmados en su revisión de salones
 * validada del periodo (ConfirmedRoomsQuery; los sin dueño ya no se ofrecen, tarea 1.53), sin bloqueo y sin otro
 * grupo del periodo en **todas** las sesiones del grupo. La ocupación y los bloqueos salen de una consulta cada una,
 * sin importar cuántos salones haya. El tipo, la capacidad y la reserva de salas los decide `RoomEligibility`.
 *
 * @phpstan-type Option array{id: string, code: string, building: ?string, type: string, capacity: int, map_key: ?string}
 */
final class SelectionRoomOptionsQuery
{
    public function __construct(private readonly ConfirmedRoomsQuery $confirmed) {}

    /**
     * Los salones que el jefe o el JPD pueden ponerle al grupo a mano durante la selección (diseño 6.14): sin el filtro
     * de SEL-02 a SEL-04, todos los activos del campus del grupo que son del departamento de la selección o no tienen
     * dueño, de cualquier tipo. `recommended` son los elegibles de forGroup(); `others`, el resto, con el motivo por el
     * que no se recomiendan si lo hay (ocupado, otro tipo, no cabe…). Las reglas de hoy (HC-02 duro; HC-05, HC-06 y
     * HC-11 como aviso) las aplica AssignSessionRoom al guardar. Sin sesiones presenciales no hay salón que poner.
     *
     * @return array{recommended: list<Option>, others: list<array{id: string, code: string, building: ?string, type: string, capacity: int, map_key: ?string, reason: ?string}>}
     */
    public function forManager(Selection $selection, Group $group): array
    {
        $options = $this->forGroup($selection, $group);

        if (! GroupSession::query()->where('group_id', $group->id)->where('kind', '!=', SessionKind::Virtual->value)->exists()) {
            return ['recommended' => [], 'others' => []];
        }

        $recommended = array_column($options['eligible'], 'id');
        $reasons = array_column($options['rejected'], 'reason', 'id');

        $others = Room::query()
            ->where('campus_id', $group->campus_id)
            ->where('is_active', true)
            ->whereNotIn('id', $recommended)
            ->where(fn (Builder $query) => $query->whereNull('department_id')->orWhere('department_id', $selection->department_id))
            ->with('building')
            ->orderBy('code')
            ->get()
            ->map(fn (Room $room): array => [
                'id' => $room->id,
                'code' => $room->code,
                'building' => $room->building?->code,
                'type' => $room->type->value,
                'capacity' => $room->capacity,
                'map_key' => $room->map_key,
                'reason' => $reasons[$room->id] ?? null,
            ])
            ->all();

        return ['recommended' => $options['eligible'], 'others' => $others];
    }

    /**
     * Los rechazados traen los mismos datos del salón más su motivo (el mapa los pone en gris por su `map_key`).
     *
     * @return array{eligible: list<Option>, rejected: list<array{id: string, code: string, building: ?string, type: string, capacity: int, map_key: ?string, reason: string}>, validated: bool} `validated`: si la revisión de salones del departamento ya está validada en el periodo
     */
    public function forGroup(Selection $selection, Group $group): array
    {
        $validated = $this->confirmed->isValidated($selection->term_id, $selection->department_id);
        $confirmed = $validated ? $this->confirmed->ids($selection->term_id, $selection->department_id) : [];
        $sessions = GroupSession::query()
            ->where('group_id', $group->id)
            ->where('kind', '!=', SessionKind::Virtual->value)
            ->get(['day', 'starts_at', 'ends_at']);

        $rooms = Room::query()
            ->where('campus_id', $group->campus_id)
            ->where('is_active', true)
            ->whereIn('type', [RoomType::Aula->value, RoomType::Laboratorio->value, RoomType::SalaComputo->value])
            ->where('department_id', $selection->department_id)
            ->whereIn('id', $confirmed)
            ->with('building')
            ->orderBy('code')
            ->get();

        $need = Subject::withTrashed()->whereKey($group->subject_id)->first(['id', 'required_space'])?->required_space;

        if ($sessions->isEmpty() || $rooms->isEmpty()) {
            return ['eligible' => [], 'rejected' => [], 'validated' => $validated];
        }

        $busy = $this->busyBySlot($group, $sessions->all(), $rooms->modelKeys());
        $blocked = $this->blockedBySlot($group, $sessions->all(), $rooms->modelKeys());

        $rows = $rooms->map(fn (Room $room): array => [
            'id' => $room->id,
            'code' => $room->code,
            'type' => $room->type,
            'capacity' => $room->capacity,
            'free' => ! isset($busy[$room->id]),
            'blocked' => isset($blocked[$room->id]),
        ])->all();

        $result = RoomEligibility::evaluate($need, $group->capacity, $rows, $this->saleReserve($selection, $group, $sessions->all(), $rooms->all(), $busy, $blocked));
        $byId = $rooms->keyBy('id');

        $option = fn (string $id): array => [
            'id' => $id,
            'code' => $byId[$id]->code,
            'building' => $byId[$id]->building?->code,
            'type' => $byId[$id]->type->value,
            'capacity' => $byId[$id]->capacity,
            'map_key' => $byId[$id]->map_key,
        ];

        return [
            'eligible' => array_map($option, $result['eligible']),
            'rejected' => array_values(array_map(fn (string $id, string $reason): array => $option($id) + ['reason' => $reason], array_keys($result['rejected']), $result['rejected'])),
            'validated' => $validated,
        ];
    }

    /**
     * Los salones con una sesión de otro grupo del periodo que se empalma con alguna del grupo, y las sesiones que
     * chocan por salón: `room_id => list<"día|inicio">`.
     *
     * @param  list<GroupSession>  $sessions
     * @param  list<string>  $roomIds
     * @return array<string, list<string>>
     */
    private function busyBySlot(Group $group, array $sessions, array $roomIds): array
    {
        $busy = [];

        GroupSession::query()
            ->where('term_id', $group->term_id)
            ->where('is_active', true)
            ->whereIn('room_id', $roomIds)
            ->where('group_id', '!=', $group->id)
            ->where(fn (Builder $query) => $this->overlappingAny($query, $sessions, 'group_sessions'))
            ->toBase()
            ->get(['room_id', 'day', 'starts_at', 'ends_at'])
            ->each(function (object $other) use (&$busy, $sessions): void {
                foreach ($sessions as $session) {
                    if ($this->touches($session, $other)) {
                        $busy[$other->room_id][] = self::slot($session);
                    }
                }
            });

        return $busy;
    }

    /**
     * Igual que la ocupación, con los bloqueos vigentes del periodo (o de todos).
     *
     * @param  list<GroupSession>  $sessions
     * @param  list<string>  $roomIds
     * @return array<string, list<string>>
     */
    private function blockedBySlot(Group $group, array $sessions, array $roomIds): array
    {
        $blocked = [];

        RoomBlock::query()
            ->whereIn('room_id', $roomIds)
            ->where(fn (Builder $query) => $query->whereNull('term_id')->orWhere('term_id', $group->term_id))
            ->where(fn (Builder $query) => $this->overlappingAny($query, $sessions, 'room_blocks'))
            ->toBase()
            ->get(['room_id', 'day', 'starts_at', 'ends_at'])
            ->each(function (object $block) use (&$blocked, $sessions): void {
                foreach ($sessions as $session) {
                    if ($this->touches($session, $block)) {
                        $blocked[$block->room_id][] = self::slot($session);
                    }
                }
            });

        return $blocked;
    }

    /**
     * SEL-04: por «día|hora» de cada sesión, las salas libres y sin bloqueo a esa hora menos los grupos de sala
     * pendientes (de las cargas incluidas de la selección, sin salón en esa misma hora).
     *
     * @param  list<GroupSession>  $sessions
     * @param  list<Room>  $rooms
     * @param  array<string, list<string>>  $busy
     * @param  array<string, list<string>>  $blocked
     * @return array<string, int>
     */
    private function saleReserve(Selection $selection, Group $group, array $sessions, array $rooms, array $busy, array $blocked): array
    {
        $salas = array_filter($rooms, fn (Room $room): bool => $room->type === RoomType::SalaComputo);

        if ($salas === []) {
            return [];
        }

        $pending = [];

        GroupSession::query()
            ->join('groups', 'groups.id', '=', 'group_sessions.group_id')
            ->join('subjects', 'subjects.id', '=', 'groups.subject_id')
            ->where('group_sessions.term_id', $group->term_id)
            ->where('group_sessions.campus_id', $group->campus_id)
            ->where('group_sessions.is_active', true)
            ->whereNull('group_sessions.room_id')
            ->where('group_sessions.group_id', '!=', $group->id)
            ->where('groups.status', '!=', GroupStatus::Cancelled->value)
            ->where('subjects.required_space', RequiredSpace::SalaComputo->value)
            ->whereExists(fn ($query) => $query->selectRaw('1')
                ->from('staffing_selection_loads')
                ->join('academic_loads', 'academic_loads.id', '=', 'staffing_selection_loads.academic_load_id')
                ->whereColumn('academic_loads.term_id', 'groups.term_id')
                ->whereColumn('academic_loads.modality_id', 'groups.modality_id')
                ->whereColumn('academic_loads.department_id', 'groups.department_id')
                ->where('staffing_selection_loads.selection_id', $selection->id))
            ->where(fn (Builder $query) => $query->where(function (Builder $any) use ($sessions): void {
                foreach ($sessions as $session) {
                    $any->orWhere(fn (Builder $one) => $one->where('group_sessions.day', $session->day)->where('group_sessions.starts_at', $session->starts_at));
                }
            }))
            ->toBase()
            ->get(['group_sessions.group_id', 'group_sessions.day', 'group_sessions.starts_at'])
            ->each(function (object $row) use (&$pending): void {
                $pending[self::key($row->day, $row->starts_at)][$row->group_id] = true;
            });

        $reserve = [];

        foreach ($sessions as $session) {
            $slot = self::slot($session);
            $free = count(array_filter($salas, fn (Room $room): bool => ! in_array($slot, $busy[$room->id] ?? [], true) && ! in_array($slot, $blocked[$room->id] ?? [], true)));
            $reserve[$slot] = $free - count($pending[self::key($session->day, $session->starts_at)] ?? []);
        }

        return $reserve;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<GroupSession>  $sessions
     */
    private function overlappingAny(Builder $query, array $sessions, string $table): void
    {
        foreach ($sessions as $session) {
            $query->orWhere(fn (Builder $one) => $one
                ->where("{$table}.day", $session->day)
                ->where("{$table}.starts_at", '<', $session->ends_at)
                ->where("{$table}.ends_at", '>', $session->starts_at));
        }
    }

    private function touches(GroupSession $session, object $other): bool
    {
        return $session->day === (int) $other->day && $session->starts_at < $other->ends_at && $session->ends_at > $other->starts_at;
    }

    private static function slot(GroupSession $session): string
    {
        return self::key($session->day, $session->starts_at);
    }

    private static function key(int|string $day, string $startsAt): string
    {
        return $day.'|'.substr($startsAt, 0, 5);
    }
}
