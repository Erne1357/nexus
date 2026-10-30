<?php

namespace App\Modules\Facilities\Policies;

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\UsesScopedContext;
use App\Modules\Facilities\Models\Room;

/**
 * Quién administra salones: el admin y la Subdirección del campus del salón (confirmado el 27 de
 * octubre de 2026; spec del carril C, "Acceso"). Los demás roles no ven ni editan. Sin `delete`: un
 * salón se desactiva.
 */
final class RoomPolicy
{
    use UsesScopedContext;

    /**
     * Si el usuario administra salones en algún campus; el listado se acota al campus activo.
     */
    public function viewAny(User $user): bool
    {
        return $this->scope($user)->has(Role::Subdireccion);
    }

    public function view(User $user, Room $room): bool
    {
        return $this->covers($user, $room->campus_id);
    }

    public function create(User $user, Campus $campus): bool
    {
        return $this->covers($user, $campus->getKey());
    }

    /**
     * Datos, estado (activar o desactivar) y bloqueos.
     */
    public function update(User $user, Room $room): bool
    {
        return $this->covers($user, $room->campus_id);
    }

    private function covers(User $user, string $campusId): bool
    {
        return $this->scope($user)->covers(Role::Subdireccion, $campusId);
    }
}
