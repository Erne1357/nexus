<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;

/**
 * Quién administra campus, según reglas-de-negocio.md, "Aprobaciones y permisos" (fila "Abrir
 * periodo, calendario, perfil de campus": `admin`, `subdireccion`):
 *
 * - El admin da de alta campus y cambia sus datos, su estado y su perfil de flujo, en todos.
 * - La subdirección ve los campus de su alcance y cambia solo su perfil de flujo (`settings`).
 * - Nadie más los administra, y nadie los borra: es un catálogo, se desactiva (no hay `delete`).
 *
 * Cada ability se resuelve con un permiso `core.campus.*` (CorePermission); el admin los tiene todos.
 */
final class CampusPolicy
{
    use AuthorizesActor;

    public function viewAny(User $user): bool
    {
        return $this->actor($user)->allowsAny(CorePermission::CampusView);
    }

    public function view(User $user, Campus $campus): bool
    {
        return $this->actor($user)->allows(CorePermission::CampusView, Target::of(campus: $campus->getKey()));
    }

    public function create(User $user): bool
    {
        return $this->actor($user)->allows(CorePermission::CampusCreate);
    }

    /**
     * Código, nombre, modalidad y estado (activar o desactivar).
     */
    public function update(User $user, Campus $campus): bool
    {
        return $this->actor($user)->allows(CorePermission::CampusUpdate);
    }

    /**
     * Solo el perfil de flujo (`settings`).
     */
    public function updateProfile(User $user, Campus $campus): bool
    {
        return $this->actor($user)->allows(CorePermission::CampusUpdateProfile, Target::of(campus: $campus->getKey()));
    }
}
