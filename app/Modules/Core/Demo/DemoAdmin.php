<?php

namespace App\Modules\Core\Demo;

use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * El admin de una base demo entra con `password` y sin cambiarla, como los usuarios de prueba de los seeders demo
 * (supuesto S5: los datos demo nunca se siembran en producción). Así la base recién sembrada se puede revisar sin
 * tocarla a mano.
 */
final class DemoAdmin
{
    public const string PASSWORD = 'password';

    public static function useDemoPassword(): void
    {
        User::query()->where('username', 'admin')->update([
            'password' => Hash::make(self::PASSWORD),
            'must_change_password' => false,
        ]);
    }
}
