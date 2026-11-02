<?php

namespace App\Modules\AcademicLoad\Listeners;

use App\Modules\Academic\Events\DepartmentDeleting;
use App\Modules\AcademicLoad\Models\AcademicLoad;

/**
 * Veta borrar un departamento que tiene cargas académicas (supuesto por confirmar): «Tiene 1 carga
 * académica.» o «Tiene N cargas académicas.». Cuenta las de cualquier periodo, campus y estado. Corre en la
 * misma petición, dentro de la transacción de DeleteDepartment.
 */
final class BlockDepartmentDeletionWithLoads
{
    public function handle(DepartmentDeleting $event): void
    {
        $total = AcademicLoad::query()->where('department_id', $event->department->id)->count();

        if ($total > 0) {
            $event->block($total === 1 ? 'Tiene 1 carga académica.' : "Tiene {$total} cargas académicas.");
        }
    }
}
