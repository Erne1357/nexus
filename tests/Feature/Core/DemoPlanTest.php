<?php

use App\Modules\AcademicLoad\AcademicLoadServiceProvider;
use App\Modules\AcademicLoad\Database\Seeders\AcademicLoadDemoSeeder;
use App\Modules\AcademicLoad\Database\Seeders\PublicationDemoSeeder;
use App\Modules\Core\Demo\DemoCheck;
use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Demo\DemoPlan;
use App\Modules\Core\Demo\DemoStep;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\DemoVolume;
use App\Modules\Core\Models\Term;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Scheduling\Database\Seeders\CleanScheduleDemoSeeder;
use App\Modules\Scheduling\Database\Seeders\SchedulingAgreementsDemoSeeder;
use App\Modules\Scheduling\SchedulingServiceProvider;
use App\Modules\Staffing\Database\Seeders\LoadClosingDemoSeeder;
use App\Modules\Staffing\Database\Seeders\StaffingDemoSeeder;
use App\Modules\Staffing\Database\Seeders\StaffingSupplyDemoSeeder;
use App\Modules\Staffing\StaffingServiceProvider;
use Illuminate\Database\Seeder;

/*
| La demo por puntos junta lo que declara cada módulo en su provider (demoSteps, demoChecks, demoQuietEvents), en el
| orden de bootstrap/providers.php y, dentro de cada módulo, en el de su lista.
*/

/**
 * Un provider de prueba que declara lo que se le pase.
 *
 * @param  list<DemoStep>  $steps
 * @param  list<DemoCheck>  $checks
 * @param  list<class-string>  $quietEvents
 */
function demoPlanModule(array $steps = [], array $checks = [], array $quietEvents = []): ModuleServiceProvider
{
    return new class(app(), $steps, $checks, $quietEvents) extends ModuleServiceProvider
    {
        public function __construct($app, private array $steps, private array $checks, private array $quietEvents)
        {
            parent::__construct($app);
        }

        public function demoSteps(): array
        {
            return $this->steps;
        }

        public function demoChecks(): array
        {
            return $this->checks;
        }

        public function demoQuietEvents(): array
        {
            return $this->quietEvents;
        }
    };
}

function demoPlanStep(DemoCheckpoint $reaches, string $label): DemoStep
{
    return new DemoStep($reaches, $label, fn () => null);
}

it('ordena los pasos por módulo y conserva el orden de cada uno', function () {
    $plan = DemoPlan::fromModules([
        demoPlanModule([
            demoPlanStep(DemoCheckpoint::Proposal, 'a1'),
            demoPlanStep(DemoCheckpoint::Catalogs, 'a2'),
            demoPlanStep(DemoCheckpoint::Catalogs, 'a3'),
        ]),
        demoPlanModule([demoPlanStep(DemoCheckpoint::Catalogs, 'b1')]),
    ]);

    $labels = fn (array $steps): array => array_map(fn (DemoStep $step): string => $step->label, $steps);

    expect($labels($plan->stepsFor(DemoCheckpoint::Catalogs)))->toBe(['a2', 'a3', 'b1'])
        ->and($labels($plan->stepsFor(DemoCheckpoint::Proposal)))->toBe(['a1'])
        ->and($plan->stepsFor(DemoCheckpoint::Closing))->toBe([]);
});

it('junta las revisiones de cada punto', function () {
    $check = new DemoCheck([DemoCheckpoint::Staffing, DemoCheckpoint::Rooms], 'cero choques', fn (): bool => true);
    $plan = DemoPlan::fromModules([demoPlanModule(checks: [$check])]);

    expect($plan->checksAt(DemoCheckpoint::Rooms))->toBe([$check])
        ->and($plan->checksAt(DemoCheckpoint::Staffing))->toBe([$check])
        ->and($plan->checksAt(DemoCheckpoint::Proposal))->toBe([]);
});

it('junta los eventos silenciados sin repetir', function () {
    $plan = DemoPlan::fromModules([
        demoPlanModule(quietEvents: ['App\Events\A', 'App\Events\B']),
        demoPlanModule(quietEvents: ['App\Events\B']),
    ]);

    expect($plan->quietEvents())->toBe(['App\Events\A', 'App\Events\B']);
});

it('los módulos reales registran los pasos de los catálogos y de la apertura del periodo', function () {
    $plan = app(DemoPlan::class);

    expect($plan->stepsFor(DemoCheckpoint::Catalogs))->not->toBeEmpty()
        ->and($plan->stepsFor(DemoCheckpoint::CatalogValidation))->not->toBeEmpty();
});

it('los pasos reales que siembran la carga le pasan a su seeder cada modalidad del volumen, no la de omisión del seeder', function () {
    Term::factory()->create(['code' => '20271']);
    $calls = new ArrayObject;

    foreach ([AcademicLoadDemoSeeder::class, PublicationDemoSeeder::class, SchedulingAgreementsDemoSeeder::class, StaffingSupplyDemoSeeder::class, StaffingDemoSeeder::class, LoadClosingDemoSeeder::class] as $seeder) {
        app()->instance($seeder, new class(class_basename($seeder), $calls) extends Seeder
        {
            public function __construct(private string $name, private ArrayObject $calls) {}

            public function run(string $modality = 'sin modalidad'): void
            {
                $this->calls[] = "{$this->name} {$modality}";
            }
        });
    }

    // El horario limpio no recibe modalidad: recorre las de los grupos del periodo.
    app()->instance(CleanScheduleDemoSeeder::class, new class extends Seeder
    {
        public function run(): void {}
    });

    foreach ([AcademicLoadServiceProvider::class, SchedulingServiceProvider::class, StaffingServiceProvider::class] as $provider) {
        foreach (app()->getProvider($provider)->demoSteps() as $step) {
            ($step->run)(DemoContext::for(DemoVolume::Massive));
        }
    }

    expect(collect($calls->getArrayCopy())->countBy()->sortKeys()->all())->toBe([
        'AcademicLoadDemoSeeder PRES' => 1,
        'LoadClosingDemoSeeder PRES' => 3,
        'PublicationDemoSeeder PRES' => 1,
        'SchedulingAgreementsDemoSeeder PRES' => 2,
        'StaffingDemoSeeder PRES' => 2,
        'StaffingSupplyDemoSeeder PRES' => 1,
    ]);
});
