<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Obliga al usuario a cambiar su contraseña en su siguiente petición. Relee al usuario con bloqueo
 * y solo escribe si no lo tenía ya, así que repetirla (o dos accesos a la vez) no llena la
 * bitácora. Se llama al entrar con la contraseña por defecto.
 */
final class RequirePasswordChange
{
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $locked = User::lockForUpdate()->findOrFail($user->getKey());

            if ($locked->must_change_password) {
                return;
            }

            $locked->forceFill(['must_change_password' => true])->save();
            $user->must_change_password = true;

            activity()
                ->performedOn($locked)
                ->event('password-change-required')
                ->log("Se le exigió cambiar la contraseña a {$locked->username}");
        });
    }
}
