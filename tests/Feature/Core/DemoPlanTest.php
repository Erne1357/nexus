<?php

use App\Modules\Core\Demo\DemoCheck;
use App\Modules\Core\Demo\DemoPlan;
use App\Modules\Core\Demo\DemoStep;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\ModuleServiceProvider;

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
