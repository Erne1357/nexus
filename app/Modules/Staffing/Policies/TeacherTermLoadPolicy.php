<?php

namespace App\Modules\Staffing\Policies;

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;
use App\Modules\Staffing\Enums\StaffingPermission;

/**
 * Quién ve la carga por docente de un departamento, según reglas-de-negocio.md, "Aprobaciones y permisos" (fila «Ver
 * la carga por docente»): el jefe de departamento y el jefe de proyecto docente de ese departamento con su campus, y
 * la Subdirección. El admin pasa por la concesión implícita del registro. Los docentes no son de un campus y sus
 * horas suman todos, pero el permiso lleva campus como los demás de Staffing: se pregunta con el campus de la
 * credencial.
 */
final class TeacherTermLoadPolicy
{
    use AuthorizesActor;

    public function viewAny(User $user, Department $department, ?Campus $campus = null): bool
    {
        return $this->actor($user)->allows(StaffingPermission::TeacherLoadView, Target::of(campus: $campus?->getKey(), unit: $department->getKey()));
    }
}
