<?php

namespace App\Modules\Scheduling\Actions\Sessions;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\Agreements\CancelAgreement;
use App\Modules\AcademicLoad\Actions\Agreements\RequestRoomLoan;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\AcademicLoad\Queries\RoomLoansQuery;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Validation\Minutes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deja los préstamos de salón (HC-10) de un grupo en un salón y un día al tamaño de sus sesiones, después de asignar,
 * mover o quitar una (tarea 3.7; reglas-de-negocio.md, "Acuerdos entre departamentos", supuesto por confirmar). Se
 * llama dentro de la transacción de AssignSessionRoom, UpdateGroupSession o RemoveGroupSession, con el grupo ya
 * bloqueado; los préstamos vivos se releen con bloqueo. La cobertura es la de RoomLoansQuery::uncovered().
 *
 * Si el salón es de otro departamento que el del grupo:
 * - Un préstamo aceptado se conserva mientras todas sus horas caigan en sesiones del grupo en ese salón y día. Si
 *   aparta horas sin sesión (la sesión se movió o se acortó), se cancela: un acuerdo aceptado no se edita, así que se
 *   pide de nuevo lo que sí se usa.
 * - La parte de cada sesión que no cubren los aceptados que quedan necesita préstamo: un propuesto exacto se queda; los
 *   demás propuestos se cancelan (CancelAgreement) y se pide uno por cada hueco (RequestRoomLoan, de quien imparte al
 *   dueño). Primero cancela y después pide: la exclusión `interdepartmental_agreements_room_no_overlap` también separa
 *   los del mismo grupo.
 * - Si el préstamo vivo de otro grupo ya aparta el salón a esa hora, el hueco no se pide y el resultado lo dice
 *   (`blocked`): la sesión se queda con su salón y HC-10 «sin préstamo». En modo estricto (asignar el salón) eso
 *   detiene con el mensaje de RequestRoomLoan y no cambia nada.
 *
 * Un salón sin dueño o del mismo departamento no necesita préstamo: cancela lo vivo de ese grupo, salón y día. Cada
 * préstamo cancelado o pedido deja su bitácora y su evento (los de AcademicLoad); solo pedir avisa al dueño.
 */
final class SyncSessionLoans
{
    public function __construct(
        private readonly CancelAgreement $cancel,
        private readonly RequestRoomLoan $request,
    ) {}

    /**
     * @throws ValidationException en modo estricto, si el salón ya está prestado o pedido a esa hora para otro grupo
     */
    public function resync(Group $group, Room $room, int $day, User $actor, bool $strict = false): LoanSyncResult
    {
        return DB::transaction(function () use ($group, $room, $day, $actor, $strict): LoanSyncResult {
            $loans = InterdepartmentalAgreement::query()
                ->where('kind', AgreementKind::RoomLoan->value)
                ->where('term_id', $group->term_id)
                ->where('group_id', $group->id)
                ->where('room_id', $room->id)
                ->where('day', $day)
                ->live()
                ->orderBy('starts_at')
                ->lockForUpdate()
                ->get();

            if ($room->department_id === null || $room->department_id === $group->department_id) {
                $this->cancelAll($loans, $actor);

                return new LoanSyncResult;
            }

            $sessions = GroupSession::query()
                ->where('group_id', $group->id)
                ->where('room_id', $room->id)
                ->where('day', $day)
                ->get(['starts_at', 'ends_at'])
                ->map(fn (GroupSession $session): array => [Minutes::of($session->starts_at), Minutes::of($session->ends_at)]);

            $accepted = $loans->filter(fn (InterdepartmentalAgreement $loan): bool => $loan->status === AgreementStatus::Accepted
                && RoomLoansQuery::uncovered($sessions, ...self::span($loan)) === []);
            $covering = $accepted->map(fn (InterdepartmentalAgreement $loan): array => self::span($loan));
            $gaps = $sessions->flatMap(fn (array $session): array => RoomLoansQuery::uncovered($covering, ...$session))->values();

            $proposed = $loans->filter(fn (InterdepartmentalAgreement $loan): bool => $loan->status === AgreementStatus::Proposed
                && $gaps->contains(self::span($loan)));
            $asked = $proposed->map(fn (InterdepartmentalAgreement $loan): array => self::span($loan));

            $this->cancelAll($loans->diff($accepted)->diff($proposed), $actor);

            return $this->requestAll($group, $room, $day, $gaps->reject(fn (array $gap): bool => $asked->contains($gap)), $actor, $strict);
        });
    }

    /**
     * @param  Collection<int, InterdepartmentalAgreement>  $loans
     */
    private function cancelAll(Collection $loans, User $actor): void
    {
        foreach ($loans as $loan) {
            $this->cancel->handle($loan, $actor);
        }
    }

    /**
     * Pide un préstamo por cada hueco, en minutos. Fuera del modo estricto, el que choca con el préstamo vivo de otro
     * grupo no se pide (RequestRoomLoan lo rechaza en un savepoint, sin abortar la transacción).
     *
     * @param  Collection<int, array{int, int}>  $gaps
     *
     * @throws ValidationException
     */
    private function requestAll(Group $group, Room $room, int $day, Collection $gaps, User $actor, bool $strict): LoanSyncResult
    {
        if ($gaps->isEmpty()) {
            return new LoanSyncResult;
        }

        $term = Term::query()->findOrFail($group->term_id);
        $campus = Campus::query()->findOrFail($group->campus_id);
        $from = Department::withTrashed()->findOrFail($group->department_id);
        $requested = false;
        $blocked = false;

        foreach ($gaps as [$start, $end]) {
            try {
                $this->request->handle($term, $campus, $from, $group->id, $room, $day, Minutes::format($start), Minutes::format($end), $actor);
                $requested = true;
            } catch (ValidationException $exception) {
                if ($strict || ! array_key_exists('room_id', $exception->errors())) {
                    throw $exception;
                }

                $blocked = true;
            }
        }

        return new LoanSyncResult($requested, $blocked);
    }

    /**
     * @return array{int, int}
     */
    private static function span(InterdepartmentalAgreement $loan): array
    {
        return [Minutes::of($loan->starts_at), Minutes::of($loan->ends_at)];
    }
}
