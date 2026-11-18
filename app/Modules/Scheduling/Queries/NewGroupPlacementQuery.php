<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\RoomBlock;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Events\NewGroupPlacementRequested;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Rules\Reanalysis\AdvisorRoom;
use App\Modules\Scheduling\Rules\Reanalysis\NewGroupPlacement;
use App\Modules\Scheduling\Rules\Reanalysis\NewGroupSpec;
use App\Modules\Scheduling\Validation\Minutes;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Event;

/**
 * El horario y el salón de «Abrir un grupo» del re-análisis (tarea 8.3b).
 *
 * - `place()` pide al motor dónde cabe el grupo nuevo (NewGroupPlacementRequested: Scheduling no ve a Solver, que
 *   contesta con la heurística y todo lo demás fijo) y le pone la clave y la capacidad de sus salones. Null si nadie
 *   contesta. Tarda lo que tarda armar la entrada de la carga (0.7–0.9 s con la demo masiva): se llama al abrir la
 *   tarjeta, nunca al pintar.
 * - `freeRooms()` son los salones a los que se puede cambiar el grupo nuevo al editarlo, solo si todas sus sesiones van en
 *   un mismo salón: del campus, activos, sin préstamo (del departamento de la carga o de nadie; supuesto por confirmar),
 *   de la capacidad del grupo o más (HC-05), del tipo que pide cada sesión (la práctica de una materia con laboratorio en
 *   laboratorio, taller o sala de cómputo; lo demás en aula, como ReanalysisSuggestionsQuery; HC-06) y libres a todas sus
 *   horas: sin otra sesión (HC-02), sin bloqueo (HC-11) y sin el préstamo vivo de otro grupo (HC-10). El más chico primero.
 * - `conflicts()` es la misma revisión para las sesiones que se van a escribir: lo que OpenReanalysisGroup revisa con los
 *   candados puestos antes de crear el grupo.
 *
 * Las dos últimas leen en cuatro consultas, sin importar cuántos salones o sesiones haya.
 */
final class NewGroupPlacementQuery
{
    public function place(AcademicLoad $load, NewGroupSpec $spec): ?NewGroupPlacement
    {
        $event = new NewGroupPlacementRequested($load, $spec);
        Event::dispatch($event);
        $placement = $event->placement();

        if ($placement === null || ! $placement->isPlaced()) {
            return $placement;
        }

        $rooms = Room::withTrashed()->whereIn('id', $placement->roomIds())->get(['id', 'code', 'capacity'])
            ->mapWithKeys(fn (Room $room): array => [$room->id => ['code' => $room->code, 'capacity' => $room->capacity]])
            ->all();

        return $placement->withRooms($rooms);
    }

    /**
     * @return list<AdvisorRoom>
     */
    public function freeRooms(AcademicLoad $load, NewGroupSpec $spec, NewGroupPlacement $placement): array
    {
        if (! $placement->isEditable()) {
            return [];
        }

        $current = $placement->roomIds();

        $needsLab = (bool) Subject::withTrashed()->whereKey($spec->subjectId)->value('needs_lab');
        $rooms = Room::query()
            ->where('campus_id', $spec->campusId)
            ->where('is_active', true)
            ->where('capacity', '>=', $spec->capacity)
            ->whereKeyNot($current[0])
            ->where(fn (Builder $query) => $query->whereNull('department_id')->orWhere('department_id', $load->department_id))
            ->orderBy('capacity')
            ->orderBy('code')
            ->get(['id', 'code', 'capacity', 'type']);
        $busy = $this->occupancy($load, $spec->campusId, $rooms->modelKeys(), $placement);

        return $rooms
            ->filter(fn (Room $room): bool => array_all($placement->sessions, fn (array $session): bool => $this->fits($room->type, $session, $needsLab)
                && $busy($room->id, $session) === null))
            ->map(fn (Room $room): AdvisorRoom => new AdvisorRoom($room->id, $room->code, $room->capacity))
            ->values()
            ->all();
    }

    /**
     * Por qué cada sesión ya no puede ir en su salón (otra sesión, un bloqueo o un préstamo a esa hora, o el salón ya no
     * está activo en el campus), o vacío si todas siguen libres.
     *
     * @return list<string>
     */
    public function conflicts(AcademicLoad $load, NewGroupSpec $spec, NewGroupPlacement $placement): array
    {
        $roomIds = $placement->roomIds();
        $usable = Room::query()->whereIn('id', $roomIds)->where('campus_id', $spec->campusId)->where('is_active', true)->pluck('code', 'id');
        $busy = $this->occupancy($load, $spec->campusId, $roomIds, $placement);
        $conflicts = [];

        foreach ($placement->sessions as $session) {
            if ($session['roomId'] === null) {
                continue;
            }

            $code = $placement->roomCode($session['roomId']) ?? 'su salón';
            $when = mb_strtolower(Minutes::dayLabel($session['day'])).' '.Minutes::labelOf($session['startsAt']).'–'.Minutes::labelOf($session['endsAt']);
            $reason = isset($usable[$session['roomId']]) ? $busy($session['roomId'], $session) : 'ya no está activo';

            if ($reason !== null) {
                $conflicts[] = "{$code} {$reason} el {$when}.";
            }
        }

        return array_values(array_unique($conflicts));
    }

    /**
     * Si el salón sirve a la sesión (HC-06): la práctica de una materia con laboratorio, en un espacio de práctica; lo demás,
     * en aula.
     *
     * @param  array{kind: string}  $session
     */
    private function fits(RoomType $type, array $session, bool $needsLab): bool
    {
        return $session['kind'] === SessionKind::Practica->value && $needsLab ? $type->isPracticeSpace() : $type === RoomType::Aula;
    }

    /**
     * Qué ocupa un salón a la hora de una sesión del grupo nuevo, o null si está libre: las sesiones activas del periodo en
     * el campus (HC-02), los bloqueos del salón (HC-11) y los préstamos vivos (HC-10), de los días del grupo nuevo.
     *
     * @param  list<string>  $roomIds
     * @return Closure(string, array{day: int, startsAt: string, endsAt: string}): ?string
     */
    private function occupancy(AcademicLoad $load, string $campusId, array $roomIds, NewGroupPlacement $placement): Closure
    {
        $days = array_values(array_unique(array_column($placement->sessions, 'day')));
        $sessions = GroupSession::query()
            ->where('term_id', $load->term_id)
            ->where('campus_id', $campusId)
            ->where('is_active', true)
            ->whereIn('room_id', $roomIds)
            ->whereIn('day', $days)
            ->toBase()
            ->get(['room_id', 'day', 'starts_at', 'ends_at'])
            ->groupBy('room_id');
        $blocks = RoomBlock::query()
            ->whereIn('room_id', $roomIds)
            ->whereIn('day', $days)
            ->where(fn (Builder $query) => $query->whereNull('term_id')->orWhere('term_id', $load->term_id))
            ->toBase()
            ->get(['room_id', 'day', 'starts_at', 'ends_at'])
            ->groupBy('room_id');
        $loans = InterdepartmentalAgreement::query()
            ->where('kind', AgreementKind::RoomLoan->value)
            ->where('term_id', $load->term_id)
            ->whereIn('status', AgreementStatus::liveValues())
            ->whereIn('room_id', $roomIds)
            ->whereIn('day', $days)
            ->toBase()
            ->get(['room_id', 'day', 'starts_at', 'ends_at'])
            ->groupBy('room_id');

        return function (string $roomId, array $session) use ($sessions, $blocks, $loans): ?string {
            $overlaps = fn (object $row): bool => (int) $row->day === $session['day']
                && Minutes::overlap(Minutes::of($row->starts_at), Minutes::of($row->ends_at), Minutes::of($session['startsAt']), Minutes::of($session['endsAt']));

            return match (true) {
                ($sessions[$roomId] ?? collect())->contains($overlaps) => 'ya está ocupado (HC-02)',
                ($blocks[$roomId] ?? collect())->contains($overlaps) => 'está bloqueado (HC-11)',
                ($loans[$roomId] ?? collect())->contains($overlaps) => 'está prestado a otro grupo (HC-10)',
                default => null,
            };
        };
    }
}
