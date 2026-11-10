<?php

namespace App\Modules\Staffing\Listeners;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadTransitioning;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Models\ChangeRequest;

/**
 * Guarda de Staffing para cerrar la carga (HC-15, tarea 4.1, P5 confirmado el 10 de noviembre de 2026): no queda
 * ninguna solicitud de cambio del departamento (mismo periodo y campus) pendiente de decidir; las aprobadas que
 * siguen sin aplicar también la detienen (supuesto por confirmar). Veta con un motivo por tipo (evento con veto,
 * LoadTransitioning).
 */
final class BlockClosingWithPendingChangeRequests
{
    public function handle(LoadTransitioning $event): void
    {
        if ($event->to !== LoadStatus::Closed) {
            return;
        }

        $load = $event->load;
        $counts = ChangeRequest::query()
            ->where('term_id', $load->term_id)->where('campus_id', $load->campus_id)->where('department_id', $load->department_id)
            ->whereIn('status', [ChangeRequestStatus::Pending, ChangeRequestStatus::Approved])
            ->selectRaw('status, count(*) as total')->groupBy('status')
            ->pluck('total', 'status');

        $pending = (int) ($counts[ChangeRequestStatus::Pending->value] ?? 0);
        $approved = (int) ($counts[ChangeRequestStatus::Approved->value] ?? 0);

        if ($pending > 0) {
            $event->block($pending === 1
                ? '1 solicitud de cambio pendiente: apruébala o recházala en Solicitudes de cambio antes de cerrar.'
                : "{$pending} solicitudes de cambio pendientes: apruébalas o recházalas en Solicitudes de cambio antes de cerrar.");
        }

        if ($approved > 0) {
            $event->block($approved === 1
                ? '1 solicitud de cambio aprobada sin aplicar: aplícala en Solicitudes de cambio antes de cerrar.'
                : "{$approved} solicitudes de cambio aprobadas sin aplicar: aplícalas en Solicitudes de cambio antes de cerrar.");
        }
    }
}
