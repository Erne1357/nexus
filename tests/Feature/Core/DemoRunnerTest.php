<?php

use App\Modules\Core\Database\Seeders\AdminUserSeeder;
use App\Modules\Core\Database\Seeders\CampusSeeder;
use App\Modules\Core\Database\Seeders\DemoTermSeeder;
use App\Modules\Core\Demo\DemoCheck;
use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Demo\DemoPlan;
use App\Modules\Core\Demo\DemoRunner;
use App\Modules\Core\Demo\DemoStep;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\DemoVolume;
use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Enums\StageStatus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\TermCalendarQuery;
use Illuminate\Support\Facades\Event;

/*
| DemoRunner corre, punto por punto, los pasos de la demo hasta el punto pedido; al llegar a cada punto corre sus
| revisiones y avisa; al final deja el calendario de 20271 con la etapa del punto en curso. Aquí con un plan armado a
| mano; los pasos reales de los módulos los prueba DemoCheckpointsTest.
*/

beforeEach(function () {
    $this->seed([CampusSeeder::class, AdminUserSeeder::class, DemoTermSeeder::class]);
});

/**
 * El runner con un plan armado a mano.
 *
 * @param  list<DemoStep>  $steps
 * @param  list<DemoCheck>  $checks
 * @param  list<class-string>  $quietEvents
 */
function demoRunnerWith(array $steps = [], array $checks = [], array $quietEvents = []): DemoRunner
{
    app()->instance(DemoPlan::class, new DemoPlan($steps, $checks, $quietEvents));

    return app(DemoRunner::class);
}

function demoRunnerStatus(Stage $stage): StageStatus
{
    $term = Term::query()->where('code', '20271')->firstOrFail();

    return collect(app(TermCalendarQuery::class)->for($term, null))->firstWhere('stage', $stage)->status;
}

final class DemoRunnerQuietEvent {}

it('corre los pasos hasta el punto pedido y no más', function () {
    $ran = [];
    $step = function (DemoCheckpoint $reaches, string $label) use (&$ran): DemoStep {
        return new DemoStep($reaches, $label, function () use (&$ran, $label): void {
            $ran[] = $label;
        });
    };

    $report = demoRunnerWith([
        $step(DemoCheckpoint::Rooms, 'salones'),
        $step(DemoCheckpoint::Catalogs, 'catálogos'),
        $step(DemoCheckpoint::Proposal, 'validar'),
    ])->run(DemoCheckpoint::Proposal, DemoContext::for(DemoVolume::Demo));

    expect($ran)->toBe(['catálogos', 'validar'])
        ->and(array_column($report->steps, 'label'))->toBe(['catálogos', 'validar']);
});

it('revisa cada punto al llegar y avisa con el punto', function () {
    $reached = [];

    demoRunnerWith(checks: [new DemoCheck([DemoCheckpoint::CatalogValidation], 'abierto', fn (): bool => true)])
        ->run(DemoCheckpoint::Proposal, DemoContext::for(DemoVolume::Demo), function (DemoCheckpoint $checkpoint, array $results) use (&$reached): void {
            $reached[$checkpoint->value] = array_column($results, 'label');
        });

    expect($reached)->toBe(['catalogos' => [], 'validacion' => ['abierto'], 'propuesta' => []]);
});

it('un punto sin pasos ni revisiones no falla', function () {
    $report = demoRunnerWith()->run(DemoCheckpoint::Closing, DemoContext::for(DemoVolume::Demo));

    expect($report->failed())->toBeFalse()
        ->and($report->steps)->toBe([]);
});

it('una revisión fallida o que lanza queda en el reporte con su motivo', function () {
    $report = demoRunnerWith(checks: [
        new DemoCheck([DemoCheckpoint::Catalogs], 'docentes', fn (): string => 'faltan 3'),
        new DemoCheck([DemoCheckpoint::Catalogs], 'alumnos', fn () => throw new RuntimeException('boom')),
        new DemoCheck([DemoCheckpoint::Catalogs], 'salones', fn (): bool => true),
    ])->run(DemoCheckpoint::Catalogs, DemoContext::for(DemoVolume::Demo));

    expect($report->failed())->toBeTrue()
        ->and(array_column($report->checks, 'reason', 'label'))->toBe(['docentes' => 'faltan 3', 'alumnos' => 'boom', 'salones' => null]);
});

it('pone en curso la etapa del punto en el calendario de 20271, también al correrlo dos veces', function () {
    $runner = demoRunnerWith();

    foreach ([1, 2] as $time) {
        $runner->run(DemoCheckpoint::Staffing, DemoContext::for(DemoVolume::Demo));

        expect(demoRunnerStatus(Stage::Staffing))->toBe(StageStatus::InProgress)
            ->and(demoRunnerStatus(Stage::Scheduling))->toBe(StageStatus::Finished)
            ->and(demoRunnerStatus(Stage::Rooms))->toBe(StageStatus::Upcoming);
    }
});

it('silencia los eventos que piden los módulos y deja pasar los demás', function () {
    $heard = [];
    Event::listen(DemoRunnerQuietEvent::class, function () use (&$heard): void {
        $heard[] = 'silenciado';
    });
    Event::listen('demo.loud', function () use (&$heard): void {
        $heard[] = 'normal';
    });

    demoRunnerWith(
        [new DemoStep(DemoCheckpoint::Catalogs, 'eventos', function (): void {
            event(new DemoRunnerQuietEvent);
            event('demo.loud');
        })],
        quietEvents: [DemoRunnerQuietEvent::class],
    )->run(DemoCheckpoint::Catalogs, DemoContext::for(DemoVolume::Demo));

    expect($heard)->toBe(['normal']);
});

it('corre los pasos como admin y al terminar devuelve al usuario que había', function () {
    $other = User::factory()->create(['username' => 'coord.prueba']);
    $this->actingAs($other);
    $user = null;

    demoRunnerWith([new DemoStep(DemoCheckpoint::Catalogs, 'quién', function () use (&$user): void {
        $user = auth()->user()?->username;
    })])->run(DemoCheckpoint::Catalogs, DemoContext::for(DemoVolume::Demo));

    expect($user)->toBe('admin')
        ->and(auth()->user()?->username)->toBe('coord.prueba');
});

it('se niega en producción', function () {
    app()->detectEnvironment(fn (): string => 'production');

    demoRunnerWith()->run(DemoCheckpoint::Catalogs, DemoContext::for(DemoVolume::Demo));
})->throws(RuntimeException::class, 'Los datos demo no se siembran en producción.');
