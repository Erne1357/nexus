<?php

namespace App\Modules\Staffing\Policies;

use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Enums\StaffingPermission;

/**
 * Quién asigna o quita el docente de un grupo (reglas-de-negocio.md, "Aprobaciones y permisos", fila «Asignar
 * docente»): el jefe del departamento del grupo y el jefe de proyecto docente de su departamento y campus. El estado
 * de la carga lo revisa la Action.
 */
final class GroupTeacherAssignmentPolicy
{
    use AuthorizesActor;

    public function create(User $user, Group $group): bool
    {
        return $this->actor($user)->allows(StaffingPermission::TeacherAssign, Target::of(
            campus: $group->campus_id,
            unit: $group->department_id,
        ));
    }
}
