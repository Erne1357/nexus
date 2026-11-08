<?php

namespace App\Modules\Scheduling\Actions\Sessions;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\Agreements\CancelAgreement;
use App\Modules\AcademicLoad\Actions\Agreements\RequestRoomLoan;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
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
 * bloqueado; los préstamos vivos se releen con bloqueo.
 *
 * Si el salón es de otro departamento que el del grupo: por cada sesión del grupo en ese salón y día, la parte que no
 * cubren los préstamos **aceptados** del grupo necesita préstamo. Un propuesto exacto para ese hueco se queda; los
 * demás propuestos se cancelan (CancelAgreement) y se pide uno por el hueco (RequestRoomLoan, de quien imparte al
 * dueño). Los aceptados que siguen tocando una sesión se conservan; los vivos que ya no tocan ninguna se cancelan.
 * Primero cancela y después pide: la exclusión `interdepartmental_agreements_room_no_overlap` también separa los del
 * mismo grupo. Un salón sin dueño o del mismo departamento no necesita préstamo: cancela lo vivo de ese grupo, salón y
 * día. Cada préstamo cancelado o pedido deja su bitácora y su evento (los de AcademicLoad). Da si pidió alguno.
 */
final class SyncSessionLoans
{
    public function __construct(
        private readonly CancelAgreement $cancel,
        private readonly RequestRoomLoan $request,
    ) {}

    /**
     * @throws ValidationException si el salón ya está prestado o pedido a esa hora para otro grupo
     */
    public function resync(Group $group, Room $room, int $day, User $actor): bool
    {
        return DB::transaction(function () use ($group, $room, $day, $actor): bool {
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

                return false;
            }

            $sessions = GroupSession::query()
                ->where('group_id', $group->id)
                ->where('room_id', $room->id)
                ->where('day', $day)
                ->orderBy('starts_at')
                ->get(['starts_at', 'ends_at'])
                ->map(fn (GroupSession $session): array => [Minutes::of($session->starts_at), Minutes::of($session->ends_at)]);

            $accepted = $loans->where('status', AgreementStatus::Accepted);
            $gaps = $sessions->flatMap(fn (array $session): array => self::uncovered($session, $accepted))->values();

            $stale = $loans->reject(function (InterdepartmentalAgreement $loan) use ($sessions, $gaps): bool {
                [$start, $end] = self::span($loan);

                return $loan->status === AgreementStatus::Accepted
                    ? $sessions->contains(fn (array $session): bool => Minutes::overlap($start, $end, ...$session))
                    : $gaps->contains(fn (array $gap): bool => $gap === [$start, $end]);
            });

            $this->cancelAll($stale, $actor);

            $requested = $loans->diff($stale)->where('status', AgreementStatus::Proposed)->map(fn (InterdepartmentalAgreement $loan): array => self::span($loan));
            $missing = $gaps->reject(fn (array $gap): bool => $requested->contains($gap));

            if ($missing->isEmpty()) {
                return false;
            }

            $term = Term::query()->findOrFail($group->term_id);
            $campus = Campus::query()->findOrFail($group->campus_id);
            $from = Department::withTrashed()->findOrFail($group->department_id);

            foreach ($missing as [$start, $end]) {
                $this->request->handle($term, $campus, $from, $group->id, $room, $day, Minutes::format($start), Minutes::format($end), $actor);
            }

            return true;
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
     * Las partes de la sesión que no cubren los préstamos aceptados (varios contiguos cubren juntos), en minutos.
     *
     * @param  array{int, int}  $session
     * @param  Collection<int, InterdepartmentalAgreement>  $accepted  ordenados por inicio
     * @return list<array{int, int}>
     */
    private static function uncovered(array $session, Collection $accepted): array
    {
        [$from, $until] = $session;
        $gaps = [];

        foreach ($accepted as $loan) {
            [$start, $end] = self::span($loan);

            if ($end <= $from || $start >= $until) {
                continue;
            }

            if ($start > $from) {
                $gaps[] = [$from, $start];
            }

            $from = max($from, $end);
        }

        if ($from < $until) {
            $gaps[] = [$from, $until];
        }

        return $gaps;
    }

    /**
     * @return array{int, int}
     */
    private static function span(InterdepartmentalAgreement $loan): array
    {
        return [Minutes::of($loan->starts_at), Minutes::of($loan->ends_at)];
    }
}
