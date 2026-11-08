<?php

namespace App\Modules\Scheduling\Actions\Sessions;

use App\Modules\Core\Models\User;
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
 * Coloca una sesión sin salón en un grupo desde el editor de horarios (tarea 3.5); el salón se asigna después (3.7).
 * No sabe de puestos: quién acomoda lo decide la pantalla con GroupPolicy::editSessions.
 *
 * En una transacción relee con bloqueo el grupo y sus sesiones del día y aplica SessionRules: grupo sin cancelar,
 * carga editable, HC-04 y sin encimarse con otra sesión del grupo. Pregunta al validador, con una foto leída después
 * del bloqueo, si la candidata empalma al docente (HC-01) y la rechaza con su mensaje; las demás reglas duras no la
 * detienen (confirmado el 7 de noviembre de 2026). Si queda pegada a una sesión del grupo del mismo tipo y sin salón,
 * se unen: la que existía se extiende (la de antes, si hay dos) y la otra se borra. Escribe en un savepoint: si otra
 * sesión gana la carrera, la exclusión de la base se traduce al mismo mensaje. Deja la bitácora en el grupo
 * (`session_placed`, con la sesión absorbida en `absorbed`) y avisa con GroupScheduleChanged después del commit.
 * Devuelve la sesión y las violaciones del grupo con la foto de después de guardar.
 */
final class PlaceGroupSession
{
    public function __construct(
        private readonly SessionRules $rules,
        private readonly ScheduleValidator $validator,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Group $group, int $day, string $startsAt, string $endsAt, SessionKind $kind, User $actor): SessionResult
    {
        $startsAt = $this->rules->time($startsAt);
        $endsAt = $this->rules->time($endsAt);

        return DB::transaction(function () use ($group, $day, $startsAt, $endsAt, $kind, $actor): SessionResult {
            $group = Group::query()->lockForUpdate()->findOrFail($group->getKey());

            $this->rules->ensureEditable($group);
            $this->rules->ensureWithinWindow($group, $day, $startsAt, $endsAt);

            $sameDay = $group->sessions()->where('day', $day)->lockForUpdate()->get();
            $this->rules->ensureNoOwnOverlap($group, $sameDay, $startsAt, $endsAt);

            $this->validator->forget();
            $this->rules->ensureNoOverlaps($this->validator->checkSession($group, new SessionCandidate($day, $startsAt, $endsAt, null, $kind)), null);

            [$before, $after] = ContiguousSessions::around($sameDay, $startsAt, $endsAt, $kind, null);
            $kept = $before ?? $after;
            $old = $kept === null ? null : SessionRules::snapshot($kept);
            $absorbed = $before !== null && $after !== null ? SessionRules::absorbed($after) : [];

            try {
                $session = DB::transaction(function () use ($group, $day, $startsAt, $endsAt, $kind, $before, $after, $kept): GroupSession {
                    if ($kept === null) {
                        return $group->sessions()->create(['day' => $day, 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'kind' => $kind]);
                    }

                    if ($before !== null && $after !== null) {
                        $after->delete();
                    }

                    $kept->update(['starts_at' => $before?->starts_at ?? $startsAt, 'ends_at' => $after?->ends_at ?? $endsAt]);

                    return $kept;
                });
            } catch (QueryException $exception) {
                throw $this->rules->overlapTaken($exception, $group, $day, $startsAt, $endsAt, null);
            }

            $session->refresh();
            $this->validator->forget();
            $validation = $this->validator->validateGroup($group);
            $label = $this->validator->stateFor($group->term_id, $group->campus_id)->labelOf($group->id);

            activity()
                ->causedBy($actor)
                ->performedOn($group)
                ->event('session_placed')
                ->withChanges(array_filter(['attributes' => SessionRules::snapshot($session), 'old' => $old]))
                ->withProperties(array_filter(['absorbed' => $absorbed]))
                ->log("Colocó una sesión de {$label} el ".Minutes::dayName($day)." de {$startsAt} a {$endsAt}");

            GroupScheduleChanged::dispatch($group);

            return new SessionResult($session, $validation);
        });
    }
}
