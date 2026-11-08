<?php

namespace App\Modules\Scheduling\Actions\Sessions;

use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Rules\SessionRules;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\SessionCandidate;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Asigna, cambia o quita (`null`) el salón de una sesión desde el editor de horarios (tarea 3.7). No sabe de puestos:
 * quién asigna lo decide la pantalla con GroupPolicy::assignRoom (el jefe del departamento del grupo).
 *
 * En una transacción relee con bloqueo el grupo y la sesión y exige que se pueda editar (SessionRules). El salón,
 * releído en compartido para que su dueño no cambie a medio camino, es del campus del grupo y está activo. Pregunta al
 * validador por la sesión en ese salón: si otro grupo lo ocupa a esa hora (HC-02) no se guarda y da su mensaje; un
 * salón más chico que el grupo (HC-05), de otro tipo (HC-06) o bloqueado (HC-11) se guarda y queda marcado
 * (confirmado el 7 de noviembre de 2026). Escribe en un savepoint que traduce la exclusión de la base si otra sesión
 * gana la carrera. Luego deja los préstamos del salón de antes y del nuevo al tamaño de las sesiones del grupo
 * (SyncSessionLoans): un salón de otro departamento se queda asignado y se pide su préstamo al dueño; mientras no lo
 * acepte, HC-10 «préstamo pendiente» (confirmado el 7 de noviembre de 2026). Si el préstamo vivo de otro grupo ya
 * aparta ese salón a esa hora, no se asigna y da el mensaje de RequestRoomLoan (modo estricto). Deja la bitácora en el grupo
 * (`session_room_assigned`, con la sesión de antes y de después) y avisa con GroupScheduleChanged después del commit.
 * Sin cambios no escribe, no registra ni avisa. El resultado dice si se pidió préstamo.
 */
final class AssignSessionRoom
{
    public const string EVENT = 'session_room_assigned';

    public function __construct(
        private readonly SessionRules $rules,
        private readonly ScheduleValidator $validator,
        private readonly SyncSessionLoans $loans,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(GroupSession $session, ?Room $room, User $actor): SessionResult
    {
        return DB::transaction(function () use ($session, $room, $actor): SessionResult {
            $group = Group::query()->lockForUpdate()->findOrFail($session->group_id);
            $session = GroupSession::query()->lockForUpdate()->findOrFail($session->getKey());
            $old = SessionRules::snapshot($session);

            $this->rules->ensureEditable($group);
            $this->validator->forget();

            if ($session->room_id === $room?->getKey()) {
                return new SessionResult($session, $this->validator->validateGroup($group));
            }

            if ($room !== null) {
                $room = Room::withTrashed()->sharedLock()->findOrFail($room->getKey());
                $this->rules->ensureRoomUsable($group, $room);
                $this->rules->ensureNoOverlaps($this->validator->checkSession($group, new SessionCandidate(
                    $session->day, $old['starts_at'], $old['ends_at'], $room->id, $session->kind, $session->id,
                )), $session->id, ['HC-02']);
            }

            $previous = $session->room_id === null ? null : Room::withTrashed()->findOrFail($session->room_id);

            try {
                DB::transaction(fn () => $session->update(['room_id' => $room?->id]));
            } catch (QueryException $exception) {
                throw $this->rules->overlapTaken($exception, $group, $session->day, $old['starts_at'], $old['ends_at'], $room?->id);
            }

            if ($previous !== null) {
                $this->loans->resync($group, $previous, $session->day, $actor);
            }

            $loans = $room === null ? new LoanSyncResult : $this->loans->resync($group, $room, $session->day, $actor, strict: true);

            $session->refresh();

            activity()
                ->causedBy($actor)
                ->performedOn($group)
                ->event(self::EVENT)
                ->withChanges(['attributes' => SessionRules::snapshot($session), 'old' => $old])
                ->log($this->describe($group, $session, $previous, $room));

            GroupScheduleChanged::dispatch($group);

            $this->validator->forget();

            return new SessionResult($session, $this->validator->validateGroup($group), loanRequested: $loans->requested);
        });
    }

    private function describe(Group $group, GroupSession $session, ?Room $previous, ?Room $room): string
    {
        $where = "la sesión de {$this->rules->label($group)} del ".SessionRules::describe($session);

        return match (true) {
            $room === null => "Quitó el salón {$previous?->code} de {$where}",
            $previous === null => "Asignó el salón {$room->code} a {$where}",
            default => "Cambió el salón de {$where} de {$previous->code} a {$room->code}",
        };
    }
}
