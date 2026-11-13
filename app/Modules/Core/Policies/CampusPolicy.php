<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;

/**
 * Quién administra campus, según reglas-de-negocio.md, "Aprobaciones y permisos" (fila "Abrir
 * periodo, calendario, perfil de modalidad": `admin`, `subdireccion`):
 *
 * - El admin da de alta campus y cambia sus datos y su estado, en todos.
 * - La subdirección solo ve los campus de su alcance (MS23, supuesto por confirmar): el campus es solo el lugar (MS3)
 *   y el perfil de flujo que antes editaba es de la modalidad.
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
     * Código, nombre y estado (activar o desactivar).
     */
    public function update(User $user, Campus $campus): bool
    {
        return $this->actor($user)->allows(CorePermission::CampusUpdate);
    }
}
