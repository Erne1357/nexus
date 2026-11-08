<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\AcademicLoad\Rules\ClockTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Lo que el validador de horarios lee de los préstamos de salón (HC-10): un salón con dueño solo lo usa otro
 * departamento con un préstamo **aceptado** que cubra el día y las horas de la sesión. Mientras el préstamo está
 * propuesto la sesión tiene choque duro «préstamo pendiente» (confirmado el 7 de noviembre de 2026).
 *
 * Un préstamo es de un grupo: cubre las sesiones de ese grupo, en ese salón, ese día y periodo, dentro de sus horas.
 * Varios préstamos aceptados contiguos del mismo grupo (9–10 y 10–11) cubren juntos una sesión de 9 a 11. Las horas
 * llegan como `H:i` o `H:i:s`.
 */
final class RoomLoansQuery
{
    /**
     * HC-10: ¿los préstamos aceptados del grupo cubren toda esta sesión en ese salón?
     */
    public function covers(string $termId, string $roomId, string $groupId, int $day, string $startsAt, string $endsAt): bool
    {
        $loans = $this->overlapping($termId, $roomId, $groupId, $day, $startsAt, $endsAt)
            ->where('status', AgreementStatus::Accepted->value)
            ->orderBy('starts_at')
            ->get(['starts_at', 'ends_at']);

        $reached = ClockTime::toSeconds($startsAt);
        foreach ($loans as $loan) {
            if (ClockTime::toSeconds($loan->starts_at) > $reached) {
                return false;
            }
            $reached = max($reached, ClockTime::toSeconds($loan->ends_at));
        }

        return $reached >= ClockTime::toSeconds($endsAt);
    }

    /**
     * Un préstamo vivo del grupo que toca la sesión, primero uno propuesto: sirve para decir «préstamo pendiente» si
     * la sesión no está cubierta y hay uno propuesto, o «sin préstamo» si no hay ninguno.
     */
    public function loanFor(string $termId, string $roomId, string $groupId, int $day, string $startsAt, string $endsAt): ?InterdepartmentalAgreement
    {
        return $this->overlapping($termId, $roomId, $groupId, $day, $startsAt, $endsAt)
            ->live()
            ->orderByRaw('status = ? desc', [AgreementStatus::Proposed->value])
            ->orderBy('starts_at')
            ->first();
    }

    /**
     * Los préstamos vivos (propuestos y aceptados) de un periodo en un campus, para validar una carga completa sin una
     * consulta por sesión.
     *
     * @return Collection<int, InterdepartmentalAgreement>
     */
    public function liveFor(string $termId, string $campusId): Collection
    {
        return InterdepartmentalAgreement::query()
            ->where('kind', AgreementKind::RoomLoan->value)
            ->where('term_id', $termId)
            ->where('campus_id', $campusId)
            ->live()
            ->orderBy('room_id')->orderBy('day')->orderBy('starts_at')
            ->get();
    }

    /**
     * Los préstamos del grupo en ese salón, día y periodo cuyas horas se cruzan con las de la sesión.
     *
     * @return Builder<InterdepartmentalAgreement>
     */
    private function overlapping(string $termId, string $roomId, string $groupId, int $day, string $startsAt, string $endsAt): Builder
    {
        return InterdepartmentalAgreement::query()
            ->where('kind', AgreementKind::RoomLoan->value)
            ->where('term_id', $termId)
            ->where('room_id', $roomId)
            ->where('group_id', $groupId)
            ->where('day', $day)
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt);
    }
}
