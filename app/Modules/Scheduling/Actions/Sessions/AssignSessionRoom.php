<?php

namespace App\Modules\Scheduling\Actions\Sessions;

use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Rules\LoadEditability;
use App\Modules\Scheduling\Rules\SessionRules;
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\SessionCandidate;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Asigna, cambia o quita (`null`) el salón de una sesión desde el editor de horarios (tarea 3.7). No sabe de puestos:
 * quién asigna lo decide la pantalla con GroupPolicy::assignRoom (el jefe del departamento del grupo).
 *
 * En una transacción toma la carga en compartido y luego bloquea el grupo y la sesión (el orden de LoadEditability; un
 * deadlock se traduce a un error de validación) y exige que se pueda editar (SessionRules). El salón,
 * releído en compartido para que su dueño no cambie a medio camino, es del campus del grupo y está activo. Pregunta al
 * validador por la sesión en ese salón: si otro grupo lo ocupa a esa hora (HC-02) no se guarda y da su mensaje; un
 * salón más chico que el grupo (HC-05), de otro tipo (HC-06) o bloqueado (HC-11) se guarda y queda marcado
 * (confirmado el 7 de noviembre de 2026). Escribe en un savepoint que traduce la exclusión de la base si otra sesión
 * gana la carrera. Luego deja los préstamos del salón de antes y del nuevo al tamaño de las sesiones del grupo
 * (SyncSessionLoans): un salón de otro departamento se queda asignado y se pide su préstamo al dueño; mientras no lo
 * acepte, HC-10 «préstamo pendiente» (confirmado el 7 de noviembre de 2026). Si el préstamo vivo de otro grupo ya
 * aparta ese salón a la hora de la sesión, no se asigna, tampoco al departamento dueño, y dice para quién; los huecos
 * de las otras sesiones del grupo en ese salón no detienen la asignación (`loanBlocked`). Deja la bitácora en el grupo
 * (`session_room_assigned`, con la sesión de antes y de después) y avisa con GroupScheduleChanged después del commit.
 * Sin cambios no escribe, no registra ni avisa. El resultado dice si se pidió préstamo.
 */
final class AssignSessionRoom
{
    public const string EVENT = 'session_room_assigned';

    public function __construct(
        private readonly SessionRules $rules,
        private readonly ScheduleValidator $validator,
        private readonly LoadEditability $editability,
        private readonly SyncSessionLoans $loans,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(GroupSession $session, ?Room $room, User $actor): SessionResult
    {
        [$session, $group, $loans] = $this->editability->guard(fn (): array => DB::transaction(function () use ($session, $room, $actor): array {
            $group = Group::query()->findOrFail($session->group_id);
            $this->editability->lockLoad($group);
            $group = Group::query()->lockForUpdate()->findOrFail($group->id);
            $session = GroupSession::query()->lockForUpdate()->findOrFail($session->getKey());
            $old = SessionRules::snapshot($session);

            $this->rules->ensureEditable($group);
            $this->validator->forget();

            if ($session->room_id === $room?->getKey()) {
                return [$session, $group, new LoanSyncResult];
            }

            if ($room !== null) {
                $room = Room::withTrashed()->sharedLock()->findOrFail($room->getKey());
                $this->rules->ensureRoomUsable($group, $room);
                $this->ensureNotLoanedToOthers($group, $session, $room);
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

            $loans = $room === null ? new LoanSyncResult : $this->loans->resync($group, $room, $session->day, $actor, strictFor: $session);

            $session->refresh();

            activity()
                ->causedBy($actor)
                ->performedOn($group)
                ->event(self::EVENT)
                ->withChanges(['attributes' => SessionRules::snapshot($session), 'old' => $old])
                ->log($this->describe($group, $session, $previous, $room));

            GroupScheduleChanged::dispatch($group);

            return [$session, $group, $loans];
        }));

        $this->validator->forget();

        return new SessionResult($session, $this->validator->validateGroup($group), loanRequested: $loans->requested, loanBlocked: $loans->blocked);
    }

    /**
     * HC-10: un salón que el préstamo vivo de otro grupo aparta a la hora de la sesión no se asigna, tampoco al
     * departamento dueño (supuesto por confirmar): «El salón MM-L2 ya está pedido para Estructura de Datos, grupo 3A el
     * martes de 16:00 a 17:00.».
     *
     * @throws ValidationException
     */
    private function ensureNotLoanedToOthers(Group $group, GroupSession $session, Room $room): void
    {
        $loan = InterdepartmentalAgreement::query()
            ->where('kind', AgreementKind::RoomLoan->value)
            ->where('term_id', $group->term_id)
            ->where('room_id', $room->id)
            ->where('group_id', '<>', $group->id)
            ->where('day', $session->day)
            ->where('starts_at', '<', $session->ends_at)
            ->where('ends_at', '>', $session->starts_at)
            ->live()
            ->orderBy('starts_at')
            ->first();

        if ($loan === null) {
            return;
        }

        $holder = Group::query()->whereKey($loan->group_id)->first();
        $state = $loan->status === AgreementStatus::Accepted ? 'prestado a' : 'pedido para';
        $label = $holder === null ? 'otro grupo' : SessionRules::label($holder);

        throw ValidationException::withMessages(['session' => "El salón {$room->code} ya está {$state} {$label} el "
            .Minutes::dayName($loan->day).' de '.substr($loan->starts_at, 0, 5).' a '.substr($loan->ends_at, 0, 5).'.']);
    }

    private function describe(Group $group, GroupSession $session, ?Room $previous, ?Room $room): string
    {
        $where = 'la sesión de '.SessionRules::label($group).' del '.SessionRules::describe($session);

        return match (true) {
            $room === null => "Quitó el salón {$previous?->code} de {$where}",
            $previous === null => "Asignó el salón {$room->code} a {$where}",
            default => "Cambió el salón de {$where} de {$previous->code} a {$room->code}",
        };
    }
}
