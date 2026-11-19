<?php

namespace Database\Seeders;

use App\Modules\Academic\Database\Seeders\AcademicDemoSeeder;
use App\Modules\Academic\Database\Seeders\DemoStaffSeeder;
use App\Modules\AcademicLoad\Database\Seeders\AcademicLoadDemoSeeder;
use App\Modules\AcademicLoad\Database\Seeders\ForecastEvaluationDemoSeeder;
use App\Modules\AcademicLoad\Database\Seeders\HistoricalGroupsDemoSeeder;
use App\Modules\CatalogValidation\Database\Seeders\CatalogValidationDemoSeeder;
use App\Modules\Core\Database\Seeders\AdminUserSeeder;
use App\Modules\Core\Database\Seeders\CampusSeeder;
use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Database\Seeders\DemoTermSeeder;
use App\Modules\Core\Database\Seeders\LoginScreenDemoSeeder;
use App\Modules\Core\Database\Seeders\OrgChartSeeder;
use App\Modules\Core\Database\Seeders\TermOpeningDemoSeeder;
use App\Modules\Demand\Database\Seeders\DemandRareCasesDemoSeeder;
use App\Modules\Facilities\Database\Seeders\FacilitiesDemoSeeder;
use App\Modules\Faculty\Database\Seeders\FacultyDemoSeeder;
use App\Modules\Faculty\Database\Seeders\TeacherCaptureDemoSeeder;
use App\Modules\Scheduling\Database\Seeders\PublicationShowcaseDemoSeeder;
use App\Modules\Scheduling\Database\Seeders\SchedulingAgreementsDemoSeeder;
use App\Modules\Scheduling\Database\Seeders\SchedulingDemoSeeder;
use App\Modules\Scheduling\Events\GroupsCreated;
use App\Modules\Solver\Database\Seeders\SolverRunsDemoSeeder;
use App\Modules\Staffing\Database\Seeders\ChangeRequestsDemoSeeder;
use App\Modules\Staffing\Database\Seeders\LoadClosingDemoSeeder;
use App\Modules\Staffing\Database\Seeders\StaffingDemoSeeder;
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
        $this->call(HistoricalGroupsDemoSeeder::class);
        $this->callWith(TermOpeningDemoSeeder::class, ['openings' => ['20271' => ['PRES']]]);
        $this->callWith(DemandRareCasesDemoSeeder::class, ['term' => '20271', 'campuses' => ['C2']]);
        $this->callWith(TeacherCaptureDemoSeeder::class, ['profile' => 'demo']);
        $this->callWith(CatalogValidationDemoSeeder::class, ['profile' => 'demo']);
        // Solo con `demo:seed --hasta=todo` (demo.showcase): las pruebas que siembran la demo no lo necesitan.
        if (config('demo.showcase', false)) {
            $this->callWith(ForecastEvaluationDemoSeeder::class, ['campuses' => ['C2']]);
        }
        // Aceptar una parte crea grupos (3.3) y GroupsCreated recalcularía los choques del campus en cada aceptación;
        // SchedulingDemoSeeder los recalcula una vez al final.
        Event::fakeFor(fn () => $this->callWith(AcademicLoadDemoSeeder::class, ['campuses' => ['C2']]), [GroupsCreated::class]);
        $scheduling = $this->resolve(SchedulingDemoSeeder::class);
        $scheduling->__invoke(['profile' => 'demo', 'campuses' => ['C2']]);
        $this->callWith(SchedulingAgreementsDemoSeeder::class, ['profile' => 'demo', 'campuses' => ['C2'], 'cleanDepartments' => $scheduling->cleanDepartments()]);
        $this->callWith(StaffingDemoSeeder::class, ['profile' => 'demo', 'campuses' => ['C2']]);
        // Cargas cerradas con justificaciones y una reabierta (tarea 4.1); SC no se toca (en la demo no cierra: tiene
        // solicitudes aprobadas sin aplicar). La que queda cerrada (CEA) la publica con sus versiones la tarea 4.3.
        $this->callWith(LoadClosingDemoSeeder::class, ['profile' => 'demo', 'campuses' => ['C2'], 'keepOpen' => ['SC']]);
        $this->showcase();
        $this->solverRuns();
        $this->changeAlternatives();
        $this->call(LoginScreenDemoSeeder::class);
    }

    /**
     * La carga que deja cerrada el paso anterior (la escoge PublicationShowcaseDemoSeeder: CEA), publicada en tres
     * versiones (tarea 4.3). Solo con `demo:seed --hasta=todo`, que prende `demo.showcase`: las pruebas que siembran la
     * demo no lo necesitan.
     */
    public function showcase(): void
    {
        if (config('demo.showcase', false)) {
            $this->callWith(PublicationShowcaseDemoSeeder::class, ['versions' => 3]);
        }
    }

    /**
     * Corridas del motor en todos sus estados (SolverRunsDemoSeeder, tarea 6.10), al final: sobre lo que dejan el cierre
     * mezclado y la publicación, con la aceptada en el departamento de menos grupos con la carga editable. Solo con
     * `demo:seed --hasta=todo`, que prende `demo.showcase`: las pruebas que siembran la demo no lo pagan.
     */
    public function solverRuns(): void
    {
        if (! config('demo.showcase', false)) {
            return;
        }

        $started = hrtime(true);
        $this->callWith(SolverRunsDemoSeeder::class, ['campuses' => ['C2'], 'apply' => true]);
        $this->command?->info(sprintf('Corridas del motor: %.1f s.', (hrtime(true) - $started) / 1e9));
    }

    /**
     * Una solicitud de cambio pendiente con choque duro y sus alternativas del motor listas (tarea 8.7), al final, para
     * que «Impacto y alternativas» también se vea en la demo mezclada: las solicitudes de StaffingDemoSeeder son solo
     * texto y el motor no tiene qué acomodar en ellas (ChangeRequestsDemoSeeder, modo `showcase`). Solo con
     * `demo:seed --hasta=todo`, que prende `demo.showcase`.
     */
    public function changeAlternatives(): void
    {
        if (! config('demo.showcase', false)) {
            return;
        }

        $started = hrtime(true);
        $this->callWith(ChangeRequestsDemoSeeder::class, ['mode' => 'showcase']);
        $this->command?->info(sprintf('Solicitud de cambio con alternativas del motor: %.1f s.', (hrtime(true) - $started) / 1e9));
    }
}
