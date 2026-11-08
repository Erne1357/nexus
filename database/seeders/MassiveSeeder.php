<?php

namespace Database\Seeders;

use App\Modules\Academic\Database\Seeders\AcademicDemoSeeder;
use App\Modules\Academic\Database\Seeders\DemoStaffSeeder;
use App\Modules\AcademicLoad\Database\Seeders\AcademicLoadDemoSeeder;
use App\Modules\CatalogValidation\Database\Seeders\CatalogValidationDemoSeeder;
use App\Modules\Core\Database\Seeders\AdminUserSeeder;
use App\Modules\Core\Database\Seeders\CampusSeeder;
use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Database\Seeders\DemoTermSeeder;
use App\Modules\Core\Database\Seeders\LoginScreenDemoSeeder;
use App\Modules\Core\Database\Seeders\OrgChartSeeder;
use App\Modules\Core\Database\Seeders\TermCalendarDemoSeeder;
use App\Modules\Core\Database\Seeders\TermHistorySeeder;
use App\Modules\Core\Database\Seeders\TermOpeningDemoSeeder;
use App\Modules\Facilities\Database\Seeders\FacilitiesDemoSeeder;
use App\Modules\Faculty\Database\Seeders\FacultyDemoSeeder;
use App\Modules\Faculty\Database\Seeders\TeacherCaptureDemoSeeder;
use App\Modules\Scheduling\Database\Seeders\SchedulingDemoSeeder;
use App\Modules\Scheduling\Events\GroupsCreated;
use App\Modules\Students\Database\Seeders\StudentsDemoSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Event;

/**
 * Volumen para probar pantallas y rendimiento: lo base, los periodos, el catálogo, los usuarios y los edificios y
 * salones de Campus I y Campus II, unos 600 docentes (350 y 250) y unos 12 mil alumnos (670 por cada una de las 12
 * carreras de Campus I y 660 por cada una de las 6 de Campus II) con unos 360 mil renglones de kárdex y su avance.
 * Imprime lo que tarda cada parte. No corre en producción.
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
            CampusSeeder::class,
            OrgChartSeeder::class,
            AdminUserSeeder::class,
            DemoTermSeeder::class,
        ]);

        $this->callWith(AcademicDemoSeeder::class, ['campuses' => ['C1', 'C2']]);
        $this->callWith(DemoStaffSeeder::class, ['campuses' => ['C1', 'C2']]);

        $this->callWith(FacilitiesDemoSeeder::class, ['campuses' => ['C1', 'C2']]);

        $this->command?->info(sprintf('Base, catálogo e instalaciones: %.1f s.', (hrtime(true) - $started) / 1e9));

        $started = hrtime(true);
        $this->callWith(FacultyDemoSeeder::class, ['teachersPerCampus' => ['C1' => 350, 'C2' => 250]]);

        $this->command?->info(sprintf('Docentes, usuarios y jefaturas en total: %.1f s.', (hrtime(true) - $started) / 1e9));

        $started = hrtime(true);
        $this->callWith(StudentsDemoSeeder::class, ['studentsPerOffering' => ['C1' => 670, 'C2' => 660]]);

        $this->command?->info(sprintf('Alumnos, kárdex y avance en total: %.1f s.', (hrtime(true) - $started) / 1e9));

        $started = hrtime(true);
        $this->call([TermHistorySeeder::class, TermCalendarDemoSeeder::class]);
        $this->callWith(TermOpeningDemoSeeder::class, ['openings' => ['20271' => ['C1', 'C2'], '20263' => ['C1', 'C2']]]);
        $this->callWith(TeacherCaptureDemoSeeder::class, ['profile' => 'massive']);
        $this->callWith(CatalogValidationDemoSeeder::class, ['profile' => 'massive']);

        $this->command?->info(sprintf('Periodos, calendarios y aperturas: %.1f s.', (hrtime(true) - $started) / 1e9));

        $started = hrtime(true);
        // Aceptar una parte crea grupos (3.3) y GroupsCreated recalcularía los choques del campus en cada aceptación;
        // SchedulingDemoSeeder los recalcula una vez al final.
        Event::fakeFor(fn () => $this->callWith(AcademicLoadDemoSeeder::class, ['campuses' => ['C1', 'C2'], 'returnEvery' => 6, 'pendingEvery' => 5]), [GroupsCreated::class]);

        $this->command?->info(sprintf('Propuestas de carrera y bandejas: %.1f s.', (hrtime(true) - $started) / 1e9));

        $started = hrtime(true);
        $this->callWith(SchedulingDemoSeeder::class, ['profile' => 'massive', 'campuses' => ['C1', 'C2']]);

        $this->command?->info(sprintf('Horarios, docentes y salones de los grupos: %.1f s.', (hrtime(true) - $started) / 1e9));

        $this->call(LoginScreenDemoSeeder::class);
    }
}
