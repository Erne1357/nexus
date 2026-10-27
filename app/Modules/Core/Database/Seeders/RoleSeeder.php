<?php

namespace App\Modules\Core\Database\Seeders;

use App\Modules\Core\Enums\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Los seis roles de R1 en spatie/permission, sin permisos finos todavía (tarea 0.7).
 * Idempotente: solo crea los que faltan.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Role::cases() as $role) {
            SpatieRole::findOrCreate($role->value);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
