<?php

namespace App\Modules\Core\Database\Seeders;

use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Enums\PasswordOrigin;
use App\Modules\Core\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * El admin de una base demo entra con `password` y sin cambiarla, como los usuarios de prueba de los seeders demo
 * (supuesto S5: los datos demo nunca se siembran en producción). Lo corre `demo:seed` en todos los puntos, también en
 * `todo`, para revisar la base recién sembrada sin tocarla a mano. Requiere AdminUserSeeder.
 */
final class DemoAdminPasswordSeeder extends Seeder
{
    use RefusesProduction;

    public const string PASSWORD = 'password';

    public function run(): void
    {
        $this->refuseProduction();

        User::query()->where('username', 'admin')->update([
            'password' => Hash::make(self::PASSWORD),
            'password_origin' => PasswordOrigin::User->value,
            'must_change_password' => false,
        ]);
    }
}
