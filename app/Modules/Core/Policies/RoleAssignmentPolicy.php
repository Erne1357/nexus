<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Models\OrgUnit;
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
 *
 * CO-02 (C10, confirmado el 24 de noviembre de 2026): aparte de esos candados, `appointUnitStaff` deja nombrar y terminar
 * solo el puesto «Personal de área» sobre una unidad, con `core.unit_staff.appoint` (dimensión unidad), que tiene de
 * fábrica la jefa de Comunicación y Difusión sobre la suya. Qué puesto se nombra y qué nombramiento se termina lo fija
 * la pantalla «Personal» de Communication, que solo trabaja con los de ese puesto en esa unidad.
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

    /**
     * CO-02: nombrar y terminar al personal de área de `$unit` (la unidad o una que cuelga de la del nombramiento).
     */
    public function appointUnitStaff(User $user, OrgUnit $unit): bool
    {
        return $this->actor($user)->allows(CorePermission::UnitStaffAppoint, Target::of(unit: $unit->getKey()));
    }
}
