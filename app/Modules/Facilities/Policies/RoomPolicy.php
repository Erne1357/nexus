<?php

namespace App\Modules\Facilities\Policies;

use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;
use App\Modules\Facilities\Enums\FacilitiesPermission;
use App\Modules\Facilities\Models\Room;

/**
 * Quién administra salones: el admin y la Subdirección del campus del salón (confirmado el 27 de
 * octubre de 2026; spec del carril C, "Acceso"). Los demás roles no ven ni editan. Cada ability se resuelve con un permiso `facilities.*` (FacilitiesPermission). Sin `delete`: un
 * salón se desactiva.
 */
final class RoomPolicy
{
    use AuthorizesActor;

    /**
     * Si el usuario administra salones en algún campus; el listado se acota a los campus que cubre (Actor::constrain()).
     */
    public function viewAny(User $user): bool
    {
        return $this->actor($user)->allowsAny(FacilitiesPermission::RoomView);
    }

    public function view(User $user, Room $room): bool
    {
        return $this->allowsOn($user, FacilitiesPermission::RoomView, $room->campus_id);
    }

    public function create(User $user, Campus $campus): bool
    {
        return $this->allowsOn($user, FacilitiesPermission::RoomCreate, $campus->getKey());
    }

    /**
     * Datos, estado (activar o desactivar) y bloqueos.
     */
    public function update(User $user, Room $room): bool
    {
        return $this->allowsOn($user, FacilitiesPermission::RoomUpdate, $room->campus_id);
    }

    private function allowsOn(User $user, FacilitiesPermission $permission, string $campusId): bool
    {
        return $this->actor($user)->allows($permission, Target::of(campus: $campusId));
    }
}
