<?php

namespace App\Modules\Faculty\Listeners;

use App\Modules\Academic\Events\DepartmentDeleting;
use App\Modules\Faculty\Models\TeacherDepartment;

/**
 * Veta borrar un departamento que tiene docentes adscritos sin borrar (S10, supuesto por confirmar). Cuenta
 * docentes no borrados con una adscripción sin borrar al departamento: hay una por docente y departamento.
 */
final class BlockDepartmentDeletionWithTeachers
{
    public function handle(DepartmentDeleting $event): void
    {
        $teachers = TeacherDepartment::query()
            ->where('department_id', $event->department->id)
            ->whereHas('teacher')
            ->count();

        if ($teachers > 0) {
            $event->block($teachers === 1 ? 'Tiene 1 docente adscrito.' : "Tiene {$teachers} docentes adscritos.");
        }
    }
}
