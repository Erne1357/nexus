<?php

namespace App\Modules\AcademicLoad\Listeners;

use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\CatalogValidation\Events\LoadStatusesRequested;

/**
 * Le dice al tablero de avance de validación (tarea 2.5, CatalogValidation) en qué estado está la carga de cada
 * departamento en cada campus del periodo. Solo lee: una consulta para todos los departamentos y campus.
 */
final class ReportLoadStatuses
{
    public function handle(LoadStatusesRequested $event): void
    {
        if ($event->campusIds === []) {
            return;
        }

        AcademicLoad::query()
            ->where('term_id', $event->term->getKey())
            ->whereIn('campus_id', $event->campusIds)
            ->get(['department_id', 'campus_id', 'status'])
            ->each(fn (AcademicLoad $load) => $event->report($load->department_id, $load->campus_id, $load->status->label(), $load->status->tone()));
    }
}
