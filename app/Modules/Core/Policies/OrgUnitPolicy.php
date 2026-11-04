<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\UsesScopedContext;

/**
 * Quién ve y corrige el organigrama: solo el admin (spec §6.1). Cada ability se resuelve con un permiso
 * `core.org_unit.*` (CorePermission) que ningún puesto concede. Qué unidades admiten cada operación (AZ-S13: los
 * departamentos académicos se manejan en Académico) lo deciden OrgUnitRules y las Actions.
 */
final class OrgUnitPolicy
{
    use UsesScopedContext;

    public function viewAny(User $user): bool
    {
        return $this->scope($user)->allows(CorePermission::OrgUnitView);
    }

    public function create(User $user): bool
    {
        return $this->scope($user)->allows(CorePermission::OrgUnitCreate);
    }

    public function update(User $user, OrgUnit $unit): bool
    {
        return $this->scope($user)->allows(CorePermission::OrgUnitUpdate);
    }

    public function move(User $user, OrgUnit $unit): bool
    {
        return $this->scope($user)->allows(CorePermission::OrgUnitMove);
    }

    /**
     * Desactivar y activar.
     */
    public function setActive(User $user, OrgUnit $unit): bool
    {
        return $this->scope($user)->allows(CorePermission::OrgUnitDeactivate);
    }
}
