<?php

namespace App\Modules\Facilities\Policies;

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\UsesScopedContext;
use App\Modules\Facilities\Models\Building;

/**
 * Quién administra edificios: el admin y la Subdirección del campus del edificio (confirmado el 27 de
 * octubre de 2026; spec del carril C, "Acceso"). Los demás roles no ven ni editan. Sin `delete`: un
 * edificio se desactiva.
 */
final class BuildingPolicy
{
    use UsesScopedContext;

    /**
     * Si el usuario administra edificios en algún campus; el listado se acota al campus activo.
     */
    public function viewAny(User $user): bool
    {
        return $this->scope($user)->has(Role::Subdireccion);
    }

    public function view(User $user, Building $building): bool
    {
        return $this->covers($user, $building->campus_id);
    }

    public function create(User $user, Campus $campus): bool
    {
        return $this->covers($user, $campus->getKey());
    }

    /**
     * Clave, nombre y estado (activar o desactivar).
     */
    public function update(User $user, Building $building): bool
    {
        return $this->covers($user, $building->campus_id);
    }

    private function covers(User $user, string $campusId): bool
    {
        return $this->scope($user)->covers(Role::Subdireccion, $campusId);
    }
}
