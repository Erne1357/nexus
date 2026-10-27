<?php

namespace Database\Seeders;

use App\Modules\Core\Database\Seeders\AdminUserSeeder;
use App\Modules\Core\Database\Seeders\CampusSeeder;
use App\Modules\Core\Database\Seeders\RoleSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Los seeders de cada módulo, en el orden del mapa de dependencias
     * (docs/dominio/modelo-de-datos.md).
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            CampusSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
