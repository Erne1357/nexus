<?php

namespace App\Modules\Scheduling\Actions\Sessions;

use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Rules\ContiguousSessions;
use App\Modules\Scheduling\Rules\LoadEditability;
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
 * si el salón ya está ocupado a esa hora (HC-02), o si otro editor lo toma antes de escribir (la exclusión de la base,
 * se reintenta en un savepoint nuevo), la mueve sin salón y lo dice en el resultado (`roomDropped`, supuesto por confirmar); las demás reglas duras no la detienen. Si queda pegada a otra sesión del grupo del mismo
 * tipo y salón, la movida se extiende y las vecinas se borran. Sin cambios no escribe, no registra ni avisa. Escribe
 * en un savepoint que traduce las exclusiones de la base, deja los préstamos del salón (HC-10) en el día de antes y en
 * el nuevo al tamaño de las sesiones del grupo (SyncSessionLoans: cancela los que ya no tocan ninguna sesión y pide los
 * huecos; cambiar solo el tipo no los toca; si el préstamo de otro grupo ya aparta el salón, no falla y lo dice en
 * `loanBlocked`), deja la bitácora en el grupo (`session_updated`, con las horas de antes y de después y las vecinas
 * absorbidas en `absorbed`) y avisa con GroupScheduleChanged después del commit.
 */
final class UpdateGroupSession
{
    public function __construct(
        private readonly SessionRules $rules,
        private readonly ScheduleValidator $validator,
        private readonly LoadEditability $editability,
        private readonly SyncSessionLoans $loans,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(GroupSession $session, int $day, string $startsAt, string $endsAt, SessionKind $kind, User $actor): SessionResult
    {
        $startsAt = $this->rules->time($startsAt);
        $endsAt = $this->rules->time($endsAt);

        $moved = $this->editability->guard(fn (): array => DB::transaction(function () use ($session, $day, $startsAt, $endsAt, $kind, $actor): array {
            $group = Group::query()->findOrFail($session->group_id);
            $this->editability->lockLoad($group);
            $group = Group::query()->lockForUpdate()->findOrFail($group->id);
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
                return [$session, $group, false, new LoanSyncResult];
            }

            try {
                $absorbed = $this->write($session, $group, $day, $startsAt, $endsAt, $kind, $roomId);
            } catch (QueryException $exception) {
                if ($roomId === null || ! $this->rules->isRoomTaken($exception)) {
                    throw $this->rules->overlapTaken($exception, $group, $day, $startsAt, $endsAt, $roomId);
                }

                // Otro editor tomó el salón entre la foto y la escritura: se mueve sin salón y se avisa.
                $roomDropped = true;
                $roomId = null;

                try {
                    $absorbed = $this->write($session->refresh(), $group, $day, $startsAt, $endsAt, $kind, null);
                } catch (QueryException $retry) {
                    throw $this->rules->overlapTaken($retry, $group, $day, $startsAt, $endsAt, null);
                }
            }

            $session->refresh();
            $loans = $this->resyncLoans($group, $old, $session, $actor);

            activity()
                ->causedBy($actor)
                ->performedOn($group)
                ->event('session_updated')
                ->withChanges(['attributes' => SessionRules::snapshot($session), 'old' => $old])
                ->withProperties(array_filter(['absorbed' => $absorbed]))
                ->log('Movió una sesión de '.SessionRules::label($group).' del '.Minutes::dayName($old['day'])." de {$old['starts_at']} a {$old['ends_at']} al ".Minutes::dayName($day)." de {$startsAt} a {$endsAt}");

            GroupScheduleChanged::dispatch($group);

            return [$session, $group, $roomDropped, $loans];
        }));

        [$session, $group, $roomDropped, $loans] = $moved;
        $this->validator->forget();

        return new SessionResult($session, $this->validator->validateGroup($group), $roomDropped, $loans->requested, $loans->blocked);
    }

    /**
     * Los préstamos del salón de antes en el día de antes y, si cambió, los del salón en su día nuevo. Cambiar solo el
     * tipo no los toca. Mover nunca falla por un préstamo: si el de otro grupo ya aparta el salón a la nueva hora, la
     * sesión se queda con HC-10 «sin préstamo» y el resultado lo dice.
     *
     * @param  array{day: int, starts_at: string, ends_at: string, kind: string, room_id: ?string}  $old
     */
    private function resyncLoans(Group $group, array $old, GroupSession $session, User $actor): LoanSyncResult
    {
        $now = SessionRules::snapshot($session);

        if ([...$now, 'kind' => $old['kind']] === $old) {
            return new LoanSyncResult;
        }

        $result = $old['room_id'] === null
            ? new LoanSyncResult
            : $this->loans->resync($group, Room::withTrashed()->findOrFail($old['room_id']), $old['day'], $actor);

        if ($session->room_id !== null && ($session->room_id !== $old['room_id'] || $session->day !== $old['day'])) {
            $result = $result->and($this->loans->resync($group, Room::withTrashed()->findOrFail($session->room_id), $session->day, $actor));
        }

        return $result;
    }

    /**
     * Escribe el movimiento en un savepoint con las vecinas de ese momento; devuelve las vecinas absorbidas.
     *
     * @return list<array{id: string, day: int, starts_at: string, ends_at: string, kind: string, room_id: ?string}>
     */
    private function write(GroupSession $session, Group $group, int $day, string $startsAt, string $endsAt, SessionKind $kind, ?string $roomId): array
    {
        return DB::transaction(function () use ($session, $group, $day, $startsAt, $endsAt, $kind, $roomId): array {
            $sameDay = $group->sessions()->where('day', $day)->whereKeyNot($session->getKey())->get();
            [$before, $after] = ContiguousSessions::around($sameDay, $startsAt, $endsAt, $kind, $roomId);
            $absorbed = SessionRules::absorbed($before, $after);

            $before?->delete();
            $after?->delete();

            $session->update([
                'day' => $day,
                'starts_at' => $before?->starts_at ?? $startsAt,
                'ends_at' => $after?->ends_at ?? $endsAt,
                'kind' => $kind,
                'room_id' => $roomId,
            ]);

            return $absorbed;
        });
    }
}
