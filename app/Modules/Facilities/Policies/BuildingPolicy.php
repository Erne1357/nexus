<?php

namespace App\Modules\Facilities\Policies;

use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;
use App\Modules\Facilities\Enums\FacilitiesPermission;
use App\Modules\Facilities\Models\Building;

/**
 * Quién administra edificios: el admin y la Subdirección del campus del edificio (confirmado el 27 de
 * octubre de 2026; spec del carril C, "Acceso"). Los demás roles no ven ni editan. Cada ability se resuelve con un permiso `facilities.*` (FacilitiesPermission). Sin `delete`: un
 * edificio se desactiva.
 */
final class BuildingPolicy
{
    use AuthorizesActor;

    /**
     * Si el usuario administra edificios en algún campus; el listado se acota a los campus que cubre (Actor::constrain()).
     */
    public function viewAny(User $user): bool
    {
        return $this->actor($user)->allowsAny(FacilitiesPermission::BuildingView);
    }

    public function view(User $user, Building $building): bool
    {
        return $this->allowsOn($user, FacilitiesPermission::BuildingView, $building->campus_id);
    }

    public function create(User $user, Campus $campus): bool
    {
        return $this->allowsOn($user, FacilitiesPermission::BuildingCreate, $campus->getKey());
    }

    /**
     * Clave, nombre y estado (activar o desactivar).
     */
    public function update(User $user, Building $building): bool
    {
        return $this->allowsOn($user, FacilitiesPermission::BuildingUpdate, $building->campus_id);
    }

    private function allowsOn(User $user, FacilitiesPermission $permission, string $campusId): bool
    {
        return $this->actor($user)->allows($permission, Target::of(campus: $campusId));
    }
}
