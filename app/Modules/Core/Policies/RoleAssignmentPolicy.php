<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;

/**
 * Quién ve personas y puestos, nombra y termina nombramientos: solo el admin nombra y termina (reglas-de-negocio.md,
 * "Aprobaciones y permisos", confirmado el 2 de noviembre de 2026); el directivo también ve, de solo lectura (AZ-03,
 * confirmado el 20 de noviembre de 2026). Cada ability se resuelve con un permiso `core.assignment.*` (CorePermission),
 * candados de CF-03: el admin los tiene todos y el directivo, de fábrica, solo `core.assignment.view`. `end` acepta la
 * clase para que una pantalla pregunte si muestra «Terminar…» sin un nombramiento en la mano. Que el puesto se maneje en
 * esta pantalla (AZ-S11) y que siempre quede un admin (AZ-S12) lo deciden las Actions.
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

    public function end(User $user, ?RoleAssignment $assignment = null): bool
    {
        return $this->actor($user)->allows(CorePermission::AssignmentEnd);
    }
}
