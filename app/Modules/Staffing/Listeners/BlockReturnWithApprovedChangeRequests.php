<?php

namespace App\Modules\Staffing\Listeners;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadTransitioning;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Models\ChangeRequest;

/**
 * Guarda de Staffing para «Volver a asignación» (`adjusting` → `staffing`, tarea 4.2): no se vuelve con solicitudes de
 * cambio aprobadas sin aplicar del departamento en el campus y el periodo, porque su cambio sigue pendiente en el
 * horario. No estorba a MarkChangeRequestApplied, que solo regresa la carga cuando ya no queda ninguna aprobada.
 */
final class BlockReturnWithApprovedChangeRequests
{
    public function handle(LoadTransitioning $event): void
    {
        if ($event->from !== LoadStatus::Adjusting || $event->to !== LoadStatus::Staffing) {
            return;
        }

        $load = $event->load;
        $approved = ChangeRequest::query()
            ->where('term_id', $load->term_id)
            ->where('modality_id', $load->modality_id)
            ->where('department_id', $load->department_id)
            ->where('status', ChangeRequestStatus::Approved)
            ->count();

        if ($approved > 0) {
            $event->block("{$approved} ".($approved === 1 ? 'solicitud de cambio aprobada sin aplicar' : 'solicitudes de cambio aprobadas sin aplicar').': aplícalas antes de volver a asignación.');
        }
    }
}
