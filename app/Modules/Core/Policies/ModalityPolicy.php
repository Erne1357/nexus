<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;

/**
 * Quién administra modalidades, con la forma de CampusPolicy (reglas-de-negocio.md, «Aprobaciones y permisos», fila
 * «Abrir periodo, calendario, perfil»: `admin`, `subdireccion`):
 *
 * - El admin da de alta modalidades y cambia sus datos, su estado y su perfil de flujo, en todas.
 * - La subdirección ve las modalidades de su alcance y cambia solo su perfil de flujo (`settings`).
 * - Nadie más las administra, y nadie las borra: es un catálogo, se desactiva (no hay `delete`).
 *
 * Cada ability se resuelve con un permiso `core.modality.*` (CorePermission); el admin los tiene todos.
 */
final class ModalityPolicy
{
    use AuthorizesActor;

    public function viewAny(User $user): bool
    {
        return $this->actor($user)->allowsAny(CorePermission::ModalityView);
    }

    public function view(User $user, Modality $modality): bool
    {
        return $this->actor($user)->allows(CorePermission::ModalityView, Target::of(modality: $modality->getKey()));
    }

    public function create(User $user): bool
    {
        return $this->actor($user)->allows(CorePermission::ModalityCreate);
    }

    /**
     * Clave, nombre y estado (activar o desactivar).
     */
    public function update(User $user, Modality $modality): bool
    {
        return $this->actor($user)->allows(CorePermission::ModalityUpdate);
    }

    /**
     * Solo el perfil de flujo (`settings`).
     */
    public function updateProfile(User $user, Modality $modality): bool
    {
        return $this->actor($user)->allows(CorePermission::ModalityUpdateProfile, Target::of(modality: $modality->getKey()));
    }
}
