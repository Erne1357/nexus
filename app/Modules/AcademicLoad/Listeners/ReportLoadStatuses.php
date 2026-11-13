<?php

namespace App\Modules\AcademicLoad\Listeners;

use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\CatalogValidation\Events\LoadStatusesRequested;

/**
 * Le dice al tablero de avance de validación (tarea 2.5, CatalogValidation) en qué estado está la carga de cada
 * departamento en cada modalidad pedida del periodo (M1). Solo lee: una consulta para todos los departamentos y
 * modalidades.
 */
final class ReportLoadStatuses
{
    public function handle(LoadStatusesRequested $event): void
    {
        if ($event->modalityIds === []) {
            return;
        }

        AcademicLoad::query()
            ->where('term_id', $event->term->getKey())
            ->whereIn('modality_id', $event->modalityIds)
            ->get(['department_id', 'modality_id', 'status'])
            ->each(fn (AcademicLoad $load) => $event->report($load->department_id, $load->modality_id, $load->status->label(), $load->status->tone()));
    }
}
