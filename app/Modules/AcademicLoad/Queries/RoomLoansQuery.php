<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Lo que el validador de horarios lee de los préstamos de salón (HC-10): un salón con dueño solo lo usa otro
 * departamento con un préstamo **aceptado** que cubra el día y las horas de la sesión. Mientras el préstamo está
 * propuesto la sesión tiene choque duro «préstamo pendiente» (confirmado el 7 de noviembre de 2026).
 *
 * Las horas llegan como `H:i` o `H:i:s`. «Cubrir» es que el préstamo empiece a la hora de la sesión o antes y
 * termine a su hora de fin o después, el mismo día y periodo, en el mismo salón y para el departamento que lo pidió.
 */
final class RoomLoansQuery
{
    /**
     * HC-10: ¿un préstamo aceptado cubre esta sesión del departamento en ese salón?
     */
    public function covers(string $termId, string $roomId, string $departmentId, int $day, string $startsAt, string $endsAt): bool
    {
        return $this->covering($termId, $roomId, $departmentId, $day, $startsAt, $endsAt)
            ->where('status', AgreementStatus::Accepted->value)
            ->exists();
    }

    /**
     * El préstamo vivo que cubre la sesión: el aceptado o, si no hay, el propuesto más reciente; nulo si no hay
     * ninguno. Sirve para decir «préstamo pendiente» o «sin préstamo».
     */
    public function loanFor(string $termId, string $roomId, string $departmentId, int $day, string $startsAt, string $endsAt): ?InterdepartmentalAgreement
    {
        return $this->covering($termId, $roomId, $departmentId, $day, $startsAt, $endsAt)
            ->live()
            ->orderByRaw('status = ? desc', [AgreementStatus::Accepted->value])
            ->latest('created_at')
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
     * @return Builder<InterdepartmentalAgreement>
     */
    private function covering(string $termId, string $roomId, string $departmentId, int $day, string $startsAt, string $endsAt): Builder
    {
        return InterdepartmentalAgreement::query()
            ->where('kind', AgreementKind::RoomLoan->value)
            ->where('term_id', $termId)
            ->where('room_id', $roomId)
            ->where('from_department_id', $departmentId)
            ->where('day', $day)
            ->where('starts_at', '<=', $startsAt)
            ->where('ends_at', '>=', $endsAt);
    }
}
