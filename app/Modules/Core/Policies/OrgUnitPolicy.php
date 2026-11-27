<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;

/**
 * Quién ve y corrige el organigrama: solo el admin lo corrige (spec §6.1); el directivo también lo ve, de solo lectura
 * (AZ-03, confirmado el 20 de noviembre de 2026). Cada ability se resuelve con un permiso `core.org_unit.*`
 * (CorePermission), candados de CF-03: el admin los tiene todos y el directivo, de fábrica, solo `core.org_unit.view`. Qué unidades admiten cada operación (AZ-S13: los
 * departamentos académicos se manejan en Administración › Departamentos) lo deciden OrgUnitRules y las Actions.
 */
final class OrgUnitPolicy
{
    use AuthorizesActor;

    public function viewAny(User $user): bool
    {
        return $this->actor($user)->allows(CorePermission::OrgUnitView);
    }

    public function create(User $user): bool
    {
        return $this->actor($user)->allows(CorePermission::OrgUnitCreate);
    }

    public function update(User $user, OrgUnit $unit): bool
    {
        return $this->actor($user)->allows(CorePermission::OrgUnitUpdate);
    }

    public function move(User $user, OrgUnit $unit): bool
    {
        return $this->actor($user)->allows(CorePermission::OrgUnitMove);
    }

    /**
     * Desactivar y activar.
     */
    public function setActive(User $user, OrgUnit $unit): bool
    {
        return $this->actor($user)->allows(CorePermission::OrgUnitDeactivate);
    }
}
