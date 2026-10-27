<?php

namespace App\Modules\Core\Database\Seeders;

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Usuario `admin` con rol admin, obligado a cambiar su contraseña en el primer acceso. Requiere
 * RoleSeeder antes.
 *
 * La contraseña inicial sale de `auth.admin_initial_password` (ADMIN_INITIAL_PASSWORD), obligatoria
 * fuera de local y testing para que producción nunca tenga una contraseña conocida. En local y
 * testing, si falta, se genera una aleatoria y se muestra en consola. Si el admin ya existe no se
 * toca su contraseña.
 */
class AdminUserSeeder extends Seeder
{
    private const string USERNAME = 'admin';

    public function run(): void
    {
        $admin = User::firstWhere('username', self::USERNAME) ?? User::create([
            'username' => self::USERNAME,
            'name' => 'Administrador',
            'password' => $this->initialPassword(),
            'is_active' => true,
            'must_change_password' => true,
        ]);

        $admin->assignRole(Role::Admin->value);
    }

    private function initialPassword(): string
    {
        $password = config('auth.admin_initial_password');

        if (is_string($password) && $password !== '') {
            return $password;
        }

        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('Define ADMIN_INITIAL_PASSWORD para crear el usuario admin fuera de local y testing.');
        }

        $password = Str::password(20);

        $this->command?->warn("Usuario admin creado con la contraseña inicial {$password} (fíjala con ADMIN_INITIAL_PASSWORD).");

        return $password;
    }
}
