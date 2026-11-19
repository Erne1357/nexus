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
 *
 * handleMany() hace lo mismo con varias sesiones de un grupo a la vez (el «Cambiar salón» del re-análisis).
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
            $group = $this->lockGroup($session->group_id);
            $session = GroupSession::query()->lockForUpdate()->findOrFail($session->getKey());
            $this->validator->forget();
            $loans = $this->assign($group, $session, $room, $actor);

            if ($loans !== null) {
                GroupScheduleChanged::dispatch($group, timesChanged: false);
            }

            return [$session, $group, $loans ?? new LoanSyncResult];
        }));

        $this->validator->forget();

        return new SessionResult($session, $this->validator->validateGroup($group), loanRequested: $loans->requested, loanBlocked: $loans->blocked);
    }

    /**
     * handle() con varias sesiones de un mismo grupo, cada una a su salón (`null` lo quita), en una transacción: el
     * «Cambiar salón» del re-análisis (ApplyReanalysisSuggestion). Cada sesión pasa por las mismas guardas y deja su
     * bitácora; el grupo avisa una vez con GroupScheduleChanged. Una que falla no deja ninguna.
     *
     * Lee la foto del validador una sola vez para todas y no valida el grupo al final, porque quien llama no pinta
     * violaciones: leerla dos veces por sesión hacía tardar de 8 a 10 s el cambio de tres sesiones en la demo masiva
     * (G-07 del recorrido final del ensayo 8.11). Una foto basta: las sesiones de un grupo no se enciman (la exclusión de
     * grupo de `group_sessions`), así que mover una no cambia el HC-02 de otra, y si otro grupo toma el salón entre
     * tanto, la exclusión de salón de la base lo detiene (SessionRules::overlapTaken()).
     *
     * @param  array<string, Room|null>  $rooms  el salón de cada sesión, por id de sesión
     *
     * @throws ValidationException si una sesión ya no es del grupo o una guarda la rechaza
     */
    public function handleMany(Group $group, array $rooms, User $actor): void
    {
        $this->editability->guard(fn () => DB::transaction(function () use ($group, $rooms, $actor): void {
            $group = $this->lockGroup($group->id);
            $sessions = GroupSession::query()
                ->where('group_id', $group->id)
                ->whereKey(array_keys($rooms))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($sessions->count() !== count($rooms)) {
                throw ValidationException::withMessages(['session' => "Una sesión ya no es del grupo {$group->code}."]);
            }

            $this->validator->forget();
            $changed = false;

            foreach ($rooms as $sessionId => $room) {
                $changed = $this->assign($group, $sessions[$sessionId], $room, $actor) !== null || $changed;
            }

            if ($changed) {
                GroupScheduleChanged::dispatch($group, timesChanged: false);
            }
        }));

        $this->validator->forget();
    }

    /**
     * La carga en compartido y luego el grupo, bloqueado: el orden de LoadEditability.
     */
    private function lockGroup(string $groupId): Group
    {
        $this->editability->lockLoad(Group::query()->findOrFail($groupId));

        return Group::query()->lockForUpdate()->findOrFail($groupId);
    }

    /**
     * Con el grupo y la sesión ya bloqueados, le pone el salón a la sesión: las guardas, la escritura, los préstamos y la
     * bitácora. Pregunta al validador con la foto que ya tenga. Null si la sesión ya estaba en ese salón (no escribe).
     *
     * @throws ValidationException
     */
    private function assign(Group $group, GroupSession $session, ?Room $room, User $actor): ?LoanSyncResult
    {
        $old = SessionRules::snapshot($session);

        $this->rules->ensureEditable($group);

        if ($session->room_id === $room?->getKey()) {
            return null;
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

        return $loans;
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
            .Minutes::dayName($loan->day).' de '.Minutes::labelOf($loan->starts_at).' a '.Minutes::labelOf($loan->ends_at).'.']);
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
