<?php

namespace App\Modules\Staffing\Listeners;

use App\Modules\Scheduling\Events\GroupsCancelling;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Models\ChangeRequest;

/**
 * Guarda de Staffing para cancelar un grupo (tarea 8.8, decidido por la coordinación de los rieles el 18 de noviembre de
 * 2026; supuesto por confirmar): un grupo con una solicitud de cambio pendiente o aprobada sin aplicar no se cancela, porque
 * la solicitud seguiría viva sobre un grupo que ya no existe (frena el cierre y se podría aprobar). Una consulta para todos
 * los grupos del evento (GroupsCancelling).
 */
final class BlockCancellingWithOpenChangeRequests
{
    public function handle(GroupsCancelling $event): void
    {
        if ($event->groups === []) {
            return;
        }

        ChangeRequest::query()
            ->whereIn('group_id', array_keys($event->groups))
            ->whereIn('status', [ChangeRequestStatus::Pending, ChangeRequestStatus::Approved])
            ->distinct()
            ->pluck('group_id')
            ->each(fn (string $groupId) => $event->block($groupId, "Resuelve primero la solicitud de cambio de {$event->groups[$groupId]}."));
    }
}
