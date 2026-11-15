<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Models\LoginNotice;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;

/**
 * Quién maneja los avisos del carrusel de la pantalla de acceso (CF-S6): ver con `core.login_notice.view` y dar de alta,
 * editar, activar, ordenar y borrar con `core.login_notice.manage`. Se pueden dar a otro puesto (CF-03); de fábrica solo el
 * admin los tiene.
 */
final class LoginNoticePolicy
{
    use AuthorizesActor;

    public function viewAny(User $user): bool
    {
        return $this->actor($user)->allowsAny(CorePermission::LoginNoticeView);
    }

    public function create(User $user): bool
    {
        return $this->actor($user)->allowsAny(CorePermission::LoginNoticeManage);
    }

    /**
     * Editar, activar o desactivar y mover en el orden.
     */
    public function update(User $user, LoginNotice $notice): bool
    {
        return $this->actor($user)->allowsAny(CorePermission::LoginNoticeManage);
    }

    public function delete(User $user, LoginNotice $notice): bool
    {
        return $this->actor($user)->allowsAny(CorePermission::LoginNoticeManage);
    }
}
