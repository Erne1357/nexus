<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;

/**
 * Quién ve personas y puestos, nombra y termina nombramientos: solo el admin (reglas-de-negocio.md, "Aprobaciones y
 * permisos", confirmado el 2 de noviembre de 2026). Cada ability se resuelve con un permiso `core.assignment.*`
 * (CorePermission) que ningún puesto concede: el admin los tiene todos. Que el puesto se maneje en esta pantalla
 * (AZ-S11) y que siempre quede un admin (AZ-S12) lo deciden las Actions.
 */
final class RoleAssignmentPolicy
{
    use AuthorizesActor;

    public function viewAny(User $user): bool
    {
        return $this->actor($user)->allows(CorePermission::AssignmentView);
    }

    public function create(User $user): bool
    {
        return $this->actor($user)->allows(CorePermission::AssignmentAppoint);
    }

    public function end(User $user, RoleAssignment $assignment): bool
    {
        return $this->actor($user)->allows(CorePermission::AssignmentEnd);
    }
}
