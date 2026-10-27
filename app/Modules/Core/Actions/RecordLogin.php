<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\User;

/**
 * Anota en `last_login_at` el momento en que el usuario entra (también cuando entra por
 * "recordarme"). No toca `updated_at`, que refleja cambios a los datos del usuario, y no va a
 * la bitácora: `last_login_at` es el registro del acceso.
 */
final class RecordLogin
{
    public function handle(User $user): void
    {
        User::withoutTimestamps(fn () => $user->forceFill(['last_login_at' => now()])->save());
    }
}
