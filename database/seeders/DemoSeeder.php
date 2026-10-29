<?php

namespace Database\Seeders;

use App\Modules\Academic\Database\Seeders\AcademicDemoSeeder;
use App\Modules\Academic\Database\Seeders\DemoStaffSeeder;
use App\Modules\Core\Database\Seeders\AdminUserSeeder;
use App\Modules\Core\Database\Seeders\CampusSeeder;
use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Database\Seeders\DemoTermSeeder;
use App\Modules\Core\Database\Seeders\RoleSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Datos demo de Campus II para desarrollo, demostraciones y browser tests: lo base, los periodos, el catálogo
 * académico de sus 6 carreras y los usuarios de prueba (docs/arquitectura/testing.md, "Datos de prueba"). No corre
 * en producción.
 *
 *     php artisan migrate:fresh --seed --seeder='Database\Seeders\DemoSeeder'
 */
class DemoSeeder extends Seeder
{
    use RefusesProduction, WithoutModelEvents;

    public function run(): void
    {
        $this->refuseProduction();

        $this->call([
            RoleSeeder::class,
            CampusSeeder::class,
            AdminUserSeeder::class,
            DemoTermSeeder::class,
        ]);

        $this->callWith(AcademicDemoSeeder::class, ['campuses' => ['C2']]);
        $this->callWith(DemoStaffSeeder::class, ['campuses' => ['C2']]);
    }
}
