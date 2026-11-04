<?php

namespace Tests\Fixtures\Modules\DemoCatalog\Policies;

use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;
use Tests\Fixtures\Modules\DemoCatalog\Enums\DemoCatalogPermission;
use Tests\Fixtures\Modules\DemoCatalog\Models\Item;

/**
 * Policy de ejemplo de permisos y alcance con AuthorizesActor (la prueban AuthorizesActorTest y
 * ModuleServiceProviderTest).
 */
class ItemPolicy
{
    use AuthorizesActor;

    /**
     * El listado no lo concede ningún puesto, solo el admin (la navegación de prueba lo pide con la ability `viewAny`).
     */
    public function viewAny(User $user): bool
    {
        return $this->actor($user)->allowsAny(DemoCatalogPermission::ItemList);
    }

    /**
     * Lo ve quien tiene `demo_catalog.item.view` en su campus.
     */
    public function view(User $user, Item $item): bool
    {
        return $this->actor($user)->allows(DemoCatalogPermission::ItemView, Target::of(campus: $item->campus_id));
    }

    /**
     * Lo edita quien tiene `demo_catalog.item.update` sobre su campus, su departamento y su carrera.
     */
    public function update(User $user, Item $item): bool
    {
        return $this->actor($user)->allows(
            DemoCatalogPermission::ItemUpdate,
            Target::of(campus: $item->campus_id, unit: $item->department_id, career: $item->career_id),
        );
    }
}
