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
 * Datos demo de Campus II para desarrollo, demostraciones y browser tests: lo base, los periodos, el catálogo
 * académico de sus 6 carreras, los usuarios de prueba, sus edificios y salones (con bloqueos y salones por defecto),
 * 120 docentes y unos 200 alumnos con su kárdex; Ene-Jun 2027 queda abierto en Campus II, con sus cargas
 * en borrador y sus revisiones de catálogo
 * (docs/arquitectura/testing.md, "Datos de prueba"). No corre en producción.
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
            CampusSeeder::class,
            OrgChartSeeder::class,
            AdminUserSeeder::class,
            DemoTermSeeder::class,
        ]);

        $this->callWith(AcademicDemoSeeder::class, ['campuses' => ['C2']]);
        $this->callWith(DemoStaffSeeder::class, ['campuses' => ['C2']]);
        $this->callWith(FacilitiesDemoSeeder::class, ['campuses' => ['C2']]);
        $this->callWith(FacultyDemoSeeder::class, ['teachersPerCampus' => ['C2' => 120]]);
        $this->callWith(StudentsDemoSeeder::class, ['studentsPerOffering' => ['C2' => 35]]);
        $this->callWith(TermOpeningDemoSeeder::class, ['openings' => ['20271' => ['C2']]]);
        $this->callWith(TeacherCaptureDemoSeeder::class, ['profile' => 'demo']);
        $this->callWith(CatalogValidationDemoSeeder::class, ['profile' => 'demo']);
        // Aceptar una parte crea grupos (3.3) y GroupsCreated recalcularía los choques del campus en cada aceptación;
        // SchedulingDemoSeeder los recalcula una vez al final.
        Event::fakeFor(fn () => $this->callWith(AcademicLoadDemoSeeder::class, ['campuses' => ['C2']]), [GroupsCreated::class]);
        $this->callWith(SchedulingDemoSeeder::class, ['profile' => 'demo', 'campuses' => ['C2']]);
        $this->call(LoginScreenDemoSeeder::class);
    }
}
