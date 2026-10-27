<?php

namespace Tests\Fixtures\Modules\DemoCatalog\Policies;

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\UsesScopedContext;
use Tests\Fixtures\Modules\DemoCatalog\Models\Item;

/**
 * Policy de ejemplo de rol y alcance con UsesScopedContext (la prueban UsesScopedContextTest y
 * ModuleServiceProviderTest).
 */
class ItemPolicy
{
    use UsesScopedContext;

    /**
     * El listado solo lo ve el admin (la navegación de prueba lo pide con la ability `viewAny`).
     */
    public function viewAny(User $user): bool
    {
        return $this->scope($user)->isAdmin();
    }

    /**
     * Lo ve quien trabaja en su campus con cualquier rol.
     */
    public function view(User $user, Item $item): bool
    {
        return $this->scope($user)->canAccessCampus($item->campus_id);
    }

    /**
     * Lo edita, en su campus, el jefe de su departamento o el coordinador de su carrera.
     */
    public function update(User $user, Item $item): bool
    {
        return $this->scope($user)->covers(
            [Role::JefeDepartamento, Role::Coordinador],
            $item->campus_id,
            department: $item->department_id,
            career: $item->career_id,
        );
    }
}
