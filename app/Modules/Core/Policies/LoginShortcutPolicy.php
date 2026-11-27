<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Models\LoginShortcut;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;

/**
 * Quién maneja los atajos de la pantalla de acceso (CF-S6): ver con `core.login_shortcut.view` y dar de alta,
 * editar, activar, ordenar y borrar con `core.login_shortcut.manage`. Se pueden dar a otro puesto (CF-03); de fábrica solo el
 * admin los tiene.
 */
final class LoginShortcutPolicy
{
    use AuthorizesActor;

    public function viewAny(User $user): bool
    {
        return $this->actor($user)->allowsAny(CorePermission::LoginShortcutView);
    }

    public function create(User $user): bool
    {
        return $this->actor($user)->allowsAny(CorePermission::LoginShortcutManage);
    }

    /**
     * Editar, activar o desactivar y mover en el orden.
     */
    public function update(User $user, LoginShortcut $shortcut): bool
    {
        return $this->actor($user)->allowsAny(CorePermission::LoginShortcutManage);
    }

    public function delete(User $user, LoginShortcut $shortcut): bool
    {
        return $this->actor($user)->allowsAny(CorePermission::LoginShortcutManage);
    }
}
