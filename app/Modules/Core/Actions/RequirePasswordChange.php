<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\User;

/**
 * Obliga al usuario a cambiar su contraseña en su siguiente petición. Solo escribe si no lo
 * tenía ya, así que repetirla no llena la bitácora. Se llama al entrar con la contraseña por
 * defecto.
 */
final class RequirePasswordChange
{
    public function handle(User $user): void
    {
        if ($user->must_change_password) {
            return;
        }

        $user->forceFill(['must_change_password' => true])->save();

        activity()
            ->performedOn($user)
            ->event('password-change-required')
            ->log("Se le exigió cambiar la contraseña a {$user->username}");
    }
}
