<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Rehace con el costo normal el hash de quien acaba de entrar, si su hash lo pide (`Hash::needsRehash`): el NIP del
 * SII se guarda con un costo menor (AC-14). Es la misma contraseña, así que no cambia su origen, no rota el remember
 * token ni deja bitácora. La llama el login después de comprobar la contraseña, con lo que el usuario escribió ya
 * normalizado (SiiNip::forUser()).
 */
final class UpgradePasswordHash
{
    public function handle(User $user, #[\SensitiveParameter] string $plain): void
    {
        if ($user->password === null || ! Hash::needsRehash($user->password)) {
            return;
        }

        $user->forceFill(['password' => Hash::make($plain)])->save();
    }
}
