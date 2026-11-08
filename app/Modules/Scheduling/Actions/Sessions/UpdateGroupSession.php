<?php

namespace App\Modules\Scheduling\Actions\Sessions;

use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Rules\ContiguousSessions;
use App\Modules\Scheduling\Rules\SessionRules;
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\SessionCandidate;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mueve una sesión de día u hora y le cambia el tipo desde el editor de horarios (tarea 3.5), con las mismas reglas
 * que PlaceGroupSession. No sabe de puestos: quién acomoda lo decide la pantalla con GroupPolicy::editSessions.
 *
 * En una transacción relee con bloqueo el grupo, la sesión y las del grupo en el día de destino. Pregunta al
 * validador por la sesión en su nuevo lugar, con su salón: un empalme del docente (HC-01) la rechaza con su mensaje;
 * si el salón ya está ocupado a esa hora (HC-02), la mueve sin salón y lo dice en el resultado (`roomDropped`,
 * supuesto por confirmar); las demás reglas duras no la detienen. Si queda pegada a otra sesión del grupo del mismo
 * tipo y salón, la movida se extiende y las vecinas se borran. Sin cambios no escribe, no registra ni avisa. Escribe
 * en un savepoint que traduce las exclusiones de la base, deja los préstamos del salón (HC-10) en el día de antes y en
 * el nuevo al tamaño de las sesiones del grupo (SyncSessionLoans: cancela los que ya no sirven y pide los que faltan;
 * los aceptados que siguen cubriendo se conservan), deja la bitácora en el grupo (`session_updated`, con las horas de
 * antes y de después) y avisa con GroupScheduleChanged después del commit.
 */
final class UpdateGroupSession
{
    public function __construct(
        private readonly SessionRules $rules,
        private readonly ScheduleValidator $validator,
        private readonly SyncSessionLoans $loans,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(GroupSession $session, int $day, string $startsAt, string $endsAt, SessionKind $kind, User $actor): SessionResult
    {
        $startsAt = $this->rules->time($startsAt);
        $endsAt = $this->rules->time($endsAt);

        return DB::transaction(function () use ($session, $day, $startsAt, $endsAt, $kind, $actor): SessionResult {
            $group = Group::query()->lockForUpdate()->findOrFail($session->group_id);
            $session = GroupSession::query()->lockForUpdate()->findOrFail($session->getKey());
            $old = SessionRules::snapshot($session);

            $this->rules->ensureEditable($group);
            $this->rules->ensureWithinWindow($group, $day, $startsAt, $endsAt);

            $sameDay = $group->sessions()->where('day', $day)->whereKeyNot($session->getKey())->lockForUpdate()->get();
            $this->rules->ensureNoOwnOverlap($group, $sameDay, $startsAt, $endsAt);

            $this->validator->forget();
            $check = $this->validator->checkSession($group, new SessionCandidate($day, $startsAt, $endsAt, $session->room_id, $kind, $session->id));
            $this->rules->ensureNoOverlaps($check, $session->id, ['HC-01']);

            $roomDropped = $session->room_id !== null && $this->rules->overlapsOf($check, $session->id, ['HC-02']) !== [];
            $roomId = $roomDropped ? null : $session->room_id;

            if (! $roomDropped && $old === [...$old, 'day' => $day, 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'kind' => $kind->value]) {
                return new SessionResult($session, $this->validator->validateGroup($group));
            }

            [$before, $after] = ContiguousSessions::around($sameDay, $startsAt, $endsAt, $kind, $roomId);

            try {
                DB::transaction(function () use ($session, $day, $startsAt, $endsAt, $kind, $roomId, $before, $after): void {
                    $before?->delete();
                    $after?->delete();

                    $session->update([
                        'day' => $day,
                        'starts_at' => $before?->starts_at ?? $startsAt,
                        'ends_at' => $after?->ends_at ?? $endsAt,
                        'kind' => $kind,
                        'room_id' => $roomId,
                    ]);
                });
            } catch (QueryException $exception) {
                throw $this->rules->overlapTaken($exception, $group, $day, $startsAt, $endsAt, $roomId);
            }

            $session->refresh();

            $this->resyncLoans($group, $old['room_id'], $old['day'], $session, $actor);

            activity()
                ->causedBy($actor)
                ->performedOn($group)
                ->event('session_updated')
                ->withChanges(['attributes' => SessionRules::snapshot($session), 'old' => $old])
                ->log("Movió una sesión de {$this->rules->label($group)} del ".Minutes::dayName($old['day'])." de {$old['starts_at']} a {$old['ends_at']} al ".Minutes::dayName($day)." de {$startsAt} a {$endsAt}");

            GroupScheduleChanged::dispatch($group);

            $this->validator->forget();

            return new SessionResult($session, $this->validator->validateGroup($group), $roomDropped);
        });
    }

    /**
     * Los préstamos del salón de antes en el día de antes y, si cambió algo, los del salón en su día nuevo.
     */
    private function resyncLoans(Group $group, ?string $oldRoomId, int $oldDay, GroupSession $session, User $actor): void
    {
        if ($oldRoomId !== null) {
            $this->loans->resync($group, Room::withTrashed()->findOrFail($oldRoomId), $oldDay, $actor);
        }

        if ($session->room_id !== null && ($session->room_id !== $oldRoomId || $session->day !== $oldDay)) {
            $this->loans->resync($group, Room::withTrashed()->findOrFail($session->room_id), $session->day, $actor);
        }
    }
}
