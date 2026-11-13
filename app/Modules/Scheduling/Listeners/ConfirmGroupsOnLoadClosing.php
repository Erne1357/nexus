<?php

namespace App\Modules\Scheduling\Listeners;

use App\Modules\AcademicLoad\Events\LoadClosing;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;

/**
 * Al cerrar la carga, sus grupos activos quedan confirmados (P1, confirmado el 10 de noviembre de 2026): los planeados
 * del departamento en el campus y el periodo pasan a `confirmed`. Corre dentro de la transacción del cierre
 * (LoadClosing), así que si falla la carga no se cierra. Deja una sola entrada de bitácora en la carga, con cuántos
 * confirmó, en lugar de una por grupo. Un grupo que nace o se reactiva después, con la carga reabierta, vuelve a
 * confirmarse al cerrar otra vez.
 */
final class ConfirmGroupsOnLoadClosing
{
    public function handle(LoadClosing $event): void
    {
        $load = $event->load;

        $confirmed = Group::query()
            ->where('term_id', $load->term_id)
            ->where('modality_id', $load->modality_id)
            ->where('department_id', $load->department_id)
            ->where('status', GroupStatus::Planned->value)
            ->update(['status' => GroupStatus::Confirmed->value, 'updated_at' => now()]);

        activity()
            ->performedOn($load)
            ->causedBy($event->actor)
            ->event('groups_confirmed')
            ->withProperties(['groups' => $confirmed])
            ->log("Confirmó {$confirmed} ".($confirmed === 1 ? 'grupo' : 'grupos').' al cerrar la carga');
    }
}
