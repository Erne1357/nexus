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
 * - Un préstamo aceptado se conserva mientras alguna sesión del grupo en ese salón y día siga dentro de sus horas:
 *   acortar o mover la sesión dentro de él no lo cancela ni lo vuelve a pedir. Se cancela solo cuando ya no lo toca
 *   ninguna sesión.
 * - La parte de cada sesión que no cubren los aceptados necesita préstamo y se pide aparte: un propuesto exacto se
 *   queda; los demás propuestos se cancelan (CancelAgreement) y se pide uno por cada hueco (RequestRoomLoan, de quien
 *   imparte al dueño). Primero cancela y después pide: la exclusión `interdepartmental_agreements_room_no_overlap`
 *   también separa los del mismo grupo.
 * - Si el préstamo vivo de otro grupo ya aparta el salón a esa hora, el hueco no se pide y el resultado lo dice
 *   (`blocked`): la sesión se queda con su salón y HC-10 «sin préstamo». Para la sesión que se asigna (`$strictFor`,
 *   AssignSessionRoom) eso detiene con el mensaje de RequestRoomLoan y no cambia nada; los huecos de las otras
 *   sesiones del grupo siguen la regla normal.
 *
 * Un salón sin dueño o del mismo departamento no necesita préstamo: cancela lo vivo de ese grupo, salón y día. Cada
 * préstamo cancelado o pedido deja su bitácora y su evento (los de AcademicLoad); pedir y cancelar avisan al dueño,
 * uno por préstamo y después del commit (NotifyDeciderOnAgreementRequested, NotifyOwnerOnLoanCancelled; confirmado el 8
 * de noviembre de 2026). Un propuesto que se suelta mientras en la misma corrida se pide uno nuevo que se empalma o se
 * pega con sus horas (alargar o mover la sesión dentro del día) es su reemplazo: se cancela con `replaced` y el dueño
 * recibe solo el aviso del nuevo. Un aceptado que se suelta siempre avisa, y un propuesto también si lo nuevo es de
 * otras horas (supuesto por confirmar). Como hay que cancelar antes de pedir, se decide con los huecos que se van a
 * pedir, sin los que ya aparta el préstamo vivo de otro grupo (que RequestRoomLoan rechazaría); si otro grupo aparta el
 * salón entre esa lectura y la petición, ese hueco no se pide y el dueño se queda sin los dos avisos.
 */
final class SyncSessionLoans
{
    public function __construct(
        private readonly CancelAgreement $cancel,
        private readonly RequestRoomLoan $request,
    ) {}

    /**
     * @throws ValidationException si el salón ya está prestado o pedido para otro grupo a la hora de `$strictFor`
     */
    public function resync(Group $group, Room $room, int $day, User $actor, ?GroupSession $strictFor = null): LoanSyncResult
    {
        return DB::transaction(function () use ($group, $room, $day, $actor, $strictFor): LoanSyncResult {
            $loans = $this->liveLoans($group, $room, $day);

            if ($room->department_id === null || $room->department_id === $group->department_id) {
                $this->cancelAll($loans, $actor);

                return new LoanSyncResult;
            }

            $sessions = $this->sessions($group, $room, $day);

            $accepted = $loans->filter(fn (InterdepartmentalAgreement $loan): bool => $loan->status === AgreementStatus::Accepted
                && $sessions->contains(fn (array $session): bool => Minutes::overlap(...self::span($loan), ...$session)));
            $covering = $accepted->map(fn (InterdepartmentalAgreement $loan): array => self::span($loan));
            $gaps = $sessions->flatMap(fn (array $session, string $id): array => array_map(
                fn (array $gap): array => [...$gap, $id === $strictFor?->getKey()],
                RoomLoansQuery::uncovered($covering, ...$session),
            ))->values();

            $proposed = $loans->filter(fn (InterdepartmentalAgreement $loan): bool => $loan->status === AgreementStatus::Proposed
                && $gaps->contains(fn (array $gap): bool => [$gap[0], $gap[1]] === self::span($loan)));
            $asked = $proposed->map(fn (InterdepartmentalAgreement $loan): array => self::span($loan));
            $requests = $gaps->reject(fn (array $gap): bool => $asked->contains([$gap[0], $gap[1]]));
            $replacing = $requests->reject(fn (array $gap): bool => $this->heldByOthers($group, $room, $day, $gap[0], $gap[1]));

            foreach ($loans->diff($accepted)->diff($proposed) as $loan) {
                $this->cancel->handle($loan, $actor, replaced: self::replacedBy($loan, $replacing));
            }

            return $this->requestAll($group, $room, $day, $requests, $actor);
        });
    }

    /**
     * Solo cancela: los préstamos vivos del grupo en ese salón y día que ya no toca ninguna de sus sesiones (todos, si
     * el salón no necesita préstamo). No pide nada. Lo usa ClearLoanRoom después de quitar el salón por un préstamo
     * rechazado o cancelado, para no dejar un aceptado que aparte el salón sin sesión que lo use; los huecos de las
     * sesiones que quedan no se piden a nombre de quien rechazó.
     */
    public function releaseUntouched(Group $group, Room $room, int $day, User $actor): void
    {
        DB::transaction(function () use ($group, $room, $day, $actor): void {
            $loans = $this->liveLoans($group, $room, $day);

            if ($room->department_id === null || $room->department_id === $group->department_id) {
                $this->cancelAll($loans, $actor);

                return;
            }

            $sessions = $this->sessions($group, $room, $day);

            $this->cancelAll($loans->reject(fn (InterdepartmentalAgreement $loan): bool => $sessions->contains(
                fn (array $session): bool => Minutes::overlap(...self::span($loan), ...$session),
            )), $actor);
        });
    }

    /**
     * Los préstamos vivos del grupo en el salón y día, releídos con bloqueo.
     *
     * @return Collection<int, InterdepartmentalAgreement>
     */
    private function liveLoans(Group $group, Room $room, int $day): Collection
    {
        return InterdepartmentalAgreement::query()
            ->where('kind', AgreementKind::RoomLoan->value)
            ->where('term_id', $group->term_id)
            ->where('group_id', $group->id)
            ->where('room_id', $room->id)
            ->where('day', $day)
            ->live()
            ->orderBy('starts_at')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Las sesiones del grupo en el salón y día, en minutos, por id.
     *
     * @return Collection<string, array{int, int}>
     */
    private function sessions(Group $group, Room $room, int $day): Collection
    {
        return GroupSession::query()
            ->where('group_id', $group->id)
            ->where('room_id', $room->id)
            ->where('day', $day)
            ->get(['id', 'starts_at', 'ends_at'])
            ->mapWithKeys(fn (GroupSession $session): array => [$session->id => [Minutes::of($session->starts_at), Minutes::of($session->ends_at)]]);
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
     * Si el préstamo que se suelta lo reemplaza un hueco que esta corrida va a pedir: solo uno propuesto (el aceptado
     * siempre avisa), y solo si el hueco se empalma con sus horas o se pega a ellas.
     *
     * @param  Collection<int, array{int, int, bool}>  $replacing  inicio, fin y si es estricto
     */
    private static function replacedBy(InterdepartmentalAgreement $loan, Collection $replacing): bool
    {
        if ($loan->status !== AgreementStatus::Proposed) {
            return false;
        }

        [$start, $end] = self::span($loan);

        return $replacing->contains(fn (array $gap): bool => Minutes::overlap($start, $end, $gap[0], $gap[1]) || $gap[0] === $end || $gap[1] === $start);
    }

    /**
     * Si el préstamo vivo de otro grupo ya aparta el salón en esas horas, en minutos: la misma exclusión que rechazaría
     * la petición (`interdepartmental_agreements_room_no_overlap`: salón, periodo, día y horas).
     */
    private function heldByOthers(Group $group, Room $room, int $day, int $start, int $end): bool
    {
        return InterdepartmentalAgreement::query()
            ->where('kind', AgreementKind::RoomLoan->value)
            ->where('term_id', $group->term_id)
            ->where('room_id', $room->id)
            ->where('day', $day)
            ->where('group_id', '!=', $group->id)
            ->live()
            ->where('starts_at', '<', Minutes::format($end))
            ->where('ends_at', '>', Minutes::format($start))
            ->exists();
    }

    /**
     * Pide un préstamo por cada hueco, en minutos. El que choca con el préstamo vivo de otro grupo no se pide
     * (RequestRoomLoan lo rechaza en un savepoint, sin abortar la transacción), salvo el hueco estricto, que detiene.
     *
     * @param  Collection<int, array{int, int, bool}>  $gaps  inicio, fin y si es estricto
     *
     * @throws ValidationException
     */
    private function requestAll(Group $group, Room $room, int $day, Collection $gaps, User $actor): LoanSyncResult
    {
        if ($gaps->isEmpty()) {
            return new LoanSyncResult;
        }

        $term = Term::query()->findOrFail($group->term_id);
        $campus = Campus::query()->findOrFail($group->campus_id);
        $from = Department::withTrashed()->findOrFail($group->department_id);
        $requested = false;
        $blocked = false;

        foreach ($gaps as [$start, $end, $strict]) {
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
