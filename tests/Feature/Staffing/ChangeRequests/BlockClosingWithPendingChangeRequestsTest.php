<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\Transitions\CloseLoad;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadTransitioning;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/ChangeRequestsTestSupport.php';

/*
| HC-15 y P5 (confirmado el 10 de noviembre de 2026): Staffing veta el cierre de la carga mientras el departamento
| tiene solicitudes de cambio pendientes, y también aprobadas sin aplicar (supuesto por confirmar).
*/

/**
 * Los motivos con los que CloseLoad rechaza el cierre de la carga del escenario.
 *
 * @return array<int, string>
 */
function closingRequestsReasons(array $scenario): array
{
    try {
        app(CloseLoad::class)->handle($scenario['load'], $scenario['jefe']);
    } catch (ValidationException $e) {
        // Solo los motivos de este veto: HC-15 (Scheduling) también puede vetar el cierre del mundo de la prueba.
        return collect($e->errors())->flatten()->filter(fn (string $reason): bool => str_contains($reason, 'solicitud'))->values()->all();
    }

    return [];
}

it('cerrar se bloquea con una solicitud pendiente, en singular', function () {
    $s = changeScenario();
    requestFor($s['group']);

    expect(closingRequestsReasons($s))->toContain('1 solicitud de cambio pendiente: apruébala o recházala en Solicitudes de cambio antes de cerrar.')
        ->and($s['load']->fresh()->status)->toBe(LoadStatus::Staffing);
});

it('cerrar se bloquea con varias pendientes, en plural', function () {
    $s = changeScenario();
    requestFor($s['group']);
    requestFor($s['group']);

    expect(closingRequestsReasons($s))->toContain('2 solicitudes de cambio pendientes: apruébalas o recházalas en Solicitudes de cambio antes de cerrar.');
});

it('cerrar se bloquea con aprobadas sin aplicar, en singular y plural', function () {
    $s = changeScenario();
    requestFor($s['group'], 'approved');

    expect(closingRequestsReasons($s))->toContain('1 solicitud de cambio aprobada sin aplicar: aplícala en Solicitudes de cambio antes de cerrar.');

    requestFor($s['group'], 'approved');

    expect(closingRequestsReasons($s))->toContain('2 solicitudes de cambio aprobadas sin aplicar: aplícalas en Solicitudes de cambio antes de cerrar.');
});

it('con pendientes y aprobadas sin aplicar da un motivo por tipo', function () {
    $s = changeScenario();
    requestFor($s['group']);
    requestFor($s['group'], 'approved');

    expect(closingRequestsReasons($s))->toContain('1 solicitud de cambio pendiente: apruébala o recházala en Solicitudes de cambio antes de cerrar.')
        ->toContain('1 solicitud de cambio aprobada sin aplicar: aplícala en Solicitudes de cambio antes de cerrar.');
});

it('no bloquea con solicitudes rechazadas o aplicadas', function () {
    $s = changeScenario();
    requestFor($s['group'], 'rejected');
    requestFor($s['group'], 'applied');

    expect(closingRequestsReasons($s))->toBe([]);
});

it('no bloquea con solicitudes de otro departamento, modalidad o periodo', function () {
    $s = changeScenario();
    $ead = Campus::factory()->create(['modality_id' => modalityOf('EAD')->id]);
    $others = [
        ['department_id' => Department::factory()->create()->id],
        ['campus_id' => $ead->id, 'modality_id' => $ead->modality_id],
        ['term_id' => Term::factory()->create()->id],
    ];

    foreach ($others as $attributes) {
        requestFor($s['group'], 'pending', $attributes);
        requestFor($s['group'], 'approved', $attributes);
    }

    expect(closingRequestsReasons($s))->toBe([]);
});

it('el listener solo actúa al cerrar', function (LoadStatus $from, LoadStatus $to) {
    $s = changeScenario();
    requestFor($s['group']);
    $load = AcademicLoad::query()->findOrFail($s['load']->id);

    $event = new LoadTransitioning($load, $from, $to);
    event($event);

    expect($event->reasons())->not->toContain('1 solicitud de cambio pendiente: apruébala o recházala en Solicitudes de cambio antes de cerrar.');
})->with([
    'a ajustes' => [LoadStatus::Staffing, LoadStatus::Adjusting],
    'a staffing' => [LoadStatus::Adjusting, LoadStatus::Staffing],
    'a asignación desde propuesta' => [LoadStatus::Proposed, LoadStatus::Staffing],
]);
