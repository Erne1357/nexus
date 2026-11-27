<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use Illuminate\Support\Collection;

/**
 * Las ventanas que los préstamos de salón vivos (propuestos o aceptados) apartan en un periodo, por salón (HC-10): lo que
 * un grupo nuevo, que no tiene préstamos, no puede ocupar, tampoco en un salón sin dueño o del departamento dueño. Es la
 * misma regla que AssignSessionRoom::ensureNotLoanedToOthers(), para quien arma la ocupación sin un grupo en mano
 * (NewGroupPlacementQuery y la entrada del motor, NewGroupSlotQuery). Una sola consulta.
 */
final class RoomLoanWindowsQuery
{
    /**
     * @param  list<string>  $roomIds
     * @param  list<int>|null  $days  null = todos
     * @return Collection<string, Collection<int, object{room_id: string, day: int, starts_at: string, ends_at: string}>>
     */
    public function for(string $termId, array $roomIds, ?array $days = null): Collection
    {
        return InterdepartmentalAgreement::query()
            ->where('kind', AgreementKind::RoomLoan->value)
            ->where('term_id', $termId)
            ->whereIn('room_id', $roomIds)
            ->when($days !== null, fn ($query) => $query->whereIn('day', $days))
            ->live()
            ->toBase()
            ->get(['room_id', 'day', 'starts_at', 'ends_at'])
            ->groupBy('room_id');
    }
}
