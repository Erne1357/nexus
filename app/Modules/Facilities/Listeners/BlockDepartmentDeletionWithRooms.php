<?php

namespace App\Modules\Facilities\Listeners;

use App\Modules\Academic\Events\DepartmentDeleting;
use App\Modules\Facilities\Models\Room;

/**
 * Veta borrar un departamento que es dueño de salones (S10, supuesto por confirmar): «Es dueño de 1 salón.»
 * o «Es dueño de N salones.». Cuenta los salones no borrados, activos o no. Corre en la misma petición,
 * dentro de la transacción de DeleteDepartment.
 */
final class BlockDepartmentDeletionWithRooms
{
    public function handle(DepartmentDeleting $event): void
    {
        $total = Room::query()->where('department_id', $event->department->id)->count();

        if ($total === 0) {
            return;
        }

        $event->block($total === 1 ? 'Es dueño de 1 salón.' : "Es dueño de {$total} salones.");
    }
}
