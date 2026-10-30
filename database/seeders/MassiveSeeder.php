<?php

namespace Database\Seeders;

use App\Modules\Academic\Database\Seeders\AcademicDemoSeeder;
use App\Modules\Academic\Database\Seeders\DemoStaffSeeder;
use App\Modules\Core\Database\Seeders\AdminUserSeeder;
use App\Modules\Core\Database\Seeders\CampusSeeder;
use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Database\Seeders\DemoTermSeeder;
use App\Modules\Core\Database\Seeders\RoleSeeder;
use App\Modules\Faculty\Database\Seeders\FacultyDemoSeeder;
use App\Modules\Students\Database\Seeders\StudentsDemoSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Volumen para probar pantallas y rendimiento: lo base, los periodos, el catálogo y los usuarios de Campus I y
 * Campus II, unos 600 docentes (350 y 250) y unos 12 mil alumnos (670 por cada una de las 12 carreras de Campus I y 660 por cada una de las 6 de
 * Campus II) con unos 360 mil renglones de kárdex y su avance. Imprime lo que tarda cada parte. No corre en
 * producción.
 *
 *     php artisan migrate:fresh --seed --seeder='Database\Seeders\MassiveSeeder'
 */
class MassiveSeeder extends Seeder
{
    use RefusesProduction, WithoutModelEvents;

    public function run(): void
    {
        $this->refuseProduction();

        $started = hrtime(true);

        $this->call([
            RoleSeeder::class,
            CampusSeeder::class,
            AdminUserSeeder::class,
            DemoTermSeeder::class,
        ]);

        $this->callWith(AcademicDemoSeeder::class, ['campuses' => ['C1', 'C2']]);
        $this->callWith(DemoStaffSeeder::class, ['campuses' => ['C1', 'C2']]);

        $this->command?->info(sprintf('Base y catálogo: %.1f s.', (hrtime(true) - $started) / 1e9));

        $started = hrtime(true);
        $this->callWith(FacultyDemoSeeder::class, ['teachersPerCampus' => ['C1' => 350, 'C2' => 250]]);

        $this->command?->info(sprintf('Docentes, usuarios y jefaturas en total: %.1f s.', (hrtime(true) - $started) / 1e9));

        $started = hrtime(true);
        $this->callWith(StudentsDemoSeeder::class, ['studentsPerOffering' => ['C1' => 670, 'C2' => 660]]);

        $this->command?->info(sprintf('Alumnos, kárdex y avance en total: %.1f s.', (hrtime(true) - $started) / 1e9));
    }
}
