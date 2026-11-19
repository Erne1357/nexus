<?php

use App\Modules\Scheduling\Rules\Cohorts;
use App\Modules\Scheduling\Rules\TypicalStudentCombination;
use App\Modules\Scheduling\Validation\ScheduleState;
use Tests\Support\Scheduling\States;

/*
| Cohortes del contrato del motor (docs/arquitectura/solver.md, «Cohortes»): hoy solo las de HC-03, con la misma lógica
| de «único grupo de la materia para la carrera» que el alumno tipo.
*/

/**
 * @param  list<string>  $groupIds
 * @return array<string, list<string>>
 */
function cohortsOf(ScheduleState $state, array $groupIds): array
{
    return Cohorts::of($state, array_map(fn (string $id) => $state->groupOrFail($id), $groupIds));
}

it('HC-03: el único grupo de una materia en su plan, semestre y campus lleva la clave plan:<plan>:<semestre>:<campus>', function () {
    $state = States::builder()
        ->campusCode('c2-id', 'C2')
        ->group(States::group('A', subject: 'CAL', campus: 'c2-id', plan: 'P1', semester: 2))
        ->group(States::group('B', subject: 'QUI', campus: 'c2-id', plan: 'P1', semester: 2))
        ->build();

    expect(cohortsOf($state, ['A', 'B']))->toBe(['A' => ['plan:P1:2:C2'], 'B' => ['plan:P1:2:C2']]);
});

it('HC-03: una materia con dos grupos activos en el semestre no lleva cohorte, ninguno de los dos', function () {
    $state = States::builder()
        ->group(States::group('A', subject: 'CAL'))
        ->group(States::group('A2', subject: 'CAL'))
        ->group(States::group('B', subject: 'QUI'))
        ->build();

    expect(cohortsOf($state, ['A', 'A2', 'B']))->toBe(['A' => [], 'A2' => [], 'B' => ['plan:P1:1:C2']]);
});

it('HC-03: otro semestre, plan, campus o modalidad, o un grupo cancelado, no cuentan como segundo grupo de la materia', function () {
    $state = States::builder()
        ->group(States::group('A', subject: 'CAL'))
        ->group(States::group('A-sem', subject: 'CAL', semester: 2))
        ->group(States::group('A-plan', subject: 'CAL', plan: 'P2'))
        ->group(States::group('A-campus', subject: 'CAL', campus: 'C1'))
        ->group(States::group('A-mod', subject: 'CAL', modality: 'EAD'))
        ->group(States::group('A-off', subject: 'CAL', active: false))
        ->build();

    expect(cohortsOf($state, ['A', 'A-sem', 'A-plan', 'A-campus', 'A-mod']))->toBe([
        'A' => ['plan:P1:1:C2'],
        'A-sem' => ['plan:P1:2:C2'],
        'A-plan' => ['plan:P2:1:C2'],
        'A-campus' => ['plan:P1:1:C1'],
        'A-mod' => ['plan:P1:1:C2'],
    ]);
});

it('HC-03: un grupo cancelado no lleva cohorte y solo se calculan los grupos que se piden', function () {
    $state = States::builder()
        ->group(States::group('A', subject: 'CAL'))
        ->group(States::group('B', subject: 'QUI'))
        ->group(States::group('C', subject: 'FIS', active: false))
        ->build();

    expect(cohortsOf($state, ['A', 'C']))->toBe(['A' => ['plan:P1:1:C2'], 'C' => []]);
});

it('HC-03: un grupo con paquete lleva la clave del plan y la del paquete, en ese orden', function () {
    $state = States::builder()
        ->campusCode('c2-id', 'C2')
        ->group(States::group('A', subject: 'CAL', campus: 'c2-id', package: 'PK', packageNumber: 1))
        ->group(States::group('B', subject: 'QUI', campus: 'c2-id', package: 'PK', packageNumber: 1))
        ->build();

    expect(cohortsOf($state, ['A', 'B']))->toBe(['A' => ['plan:P1:1:C2', 'package:PK:C2:1'], 'B' => ['plan:P1:1:C2', 'package:PK:C2:1']]);
});

it('HC-03: dos instancias del mismo paquete son cohortes distintas', function () {
    $state = States::builder()
        ->group(States::group('A1', subject: 'CAL', package: 'PK', packageNumber: 1))
        ->group(States::group('A2', subject: 'CAL', package: 'PK', packageNumber: 2))
        ->build();

    // La materia tiene dos grupos: no hay cohorte de plan, solo la de cada instancia.
    expect(cohortsOf($state, ['A1', 'A2']))->toBe(['A1' => ['package:PK:C2:1'], 'A2' => ['package:PK:C2:2']]);
});

it('HC-03: un grupo cancelado con paquete no lleva la cohorte del paquete, y sin paquete no cambia nada', function () {
    $state = States::builder()
        ->group(States::group('A', subject: 'CAL', active: false, package: 'PK', packageNumber: 1))
        ->group(States::group('B', subject: 'QUI'))
        ->build();

    expect(cohortsOf($state, ['A', 'B']))->toBe(['A' => [], 'B' => ['plan:P1:1:C2']]);
});

it('HC-03: un grupo de paquete sin campus lleva el marcador «none» en la clave', function () {
    $state = States::builder()
        ->group(States::group('A', subject: 'CAL', campus: '', package: 'PK', packageNumber: 1))
        ->build();

    expect(cohortsOf($state, ['A'])['A'])->toContain('package:PK:none:1');
});

/**
 * Las claves `combination:` de los grupos dados.
 *
 * @param  list<string>  $groupIds
 * @return array<string, list<string>>
 */
function combinationCohortsOf(ScheduleState $state, array $groupIds): array
{
    return Cohorts::combinations($state, array_map(fn (string $id) => $state->groupOrFail($id), $groupIds));
}

it('HC-03: en un semestre con una materia de varios grupos, la combinación del alumno tipo lleva a los únicos y al grupo de cada otra materia que hoy cabe', function () {
    $state = States::builder()
        ->group(States::group('CAL', subject: 'CAL'))
        ->group(States::group('QUI-A', subject: 'QUI', code: 'A'))
        ->group(States::group('QUI-B', subject: 'QUI', code: 'B'))
        ->session('CAL', 1, '07:00', '09:00')
        ->session('QUI-A', 1, '08:00', '10:00')
        ->session('QUI-B', 2, '07:00', '09:00')
        ->build();

    expect(combinationCohortsOf($state, ['CAL', 'QUI-A', 'QUI-B']))->toBe([
        'CAL' => ['combination:P1:1:C2'],
        'QUI-A' => [],
        'QUI-B' => ['combination:P1:1:C2'],
    ])->and(cohortsOf($state, ['CAL', 'QUI-A', 'QUI-B']))->toBe(['CAL' => ['plan:P1:1:C2'], 'QUI-A' => [], 'QUI-B' => []]);
});

it('HC-03: la combinación junta solo los grupos de la combinación vigente, la que ve el validador: las opciones de una materia no comparten la clave entre sí', function () {
    $state = States::builder()
        ->group(States::group('CAL', subject: 'CAL'))
        ->group(States::group('QUI-A', subject: 'QUI', code: 'A'))
        ->group(States::group('QUI-B', subject: 'QUI', code: 'B'))
        ->group(States::group('FIS-A', subject: 'FIS', code: 'A'))
        ->group(States::group('FIS-B', subject: 'FIS', code: 'B'))
        ->session('CAL', 1, '07:00', '09:00')
        ->session('QUI-A', 1, '08:00', '10:00')
        ->session('QUI-B', 2, '07:00', '09:00')
        ->session('FIS-A', 3, '07:00', '09:00')
        ->session('FIS-B', 2, '08:00', '10:00')
        ->build();
    $ids = ['CAL', 'QUI-A', 'QUI-B', 'FIS-A', 'FIS-B'];
    $keyed = array_keys(array_filter(combinationCohortsOf($state, $ids)));
    $slots = fn (string $id): array => array_map(fn ($session): array => ['day' => $session->day, 'start' => $session->start, 'end' => $session->end], $state->sessionsOf($id));

    expect($keyed)->toBe(['CAL', 'QUI-B', 'FIS-A'])
        ->and(TypicalStudentCombination::exists(['CAL' => [$slots('CAL')], 'QUI' => [$slots('QUI-B')], 'FIS' => [$slots('FIS-A')]]))->toBeTrue()
        ->and(TypicalStudentCombination::exists(['CAL' => [$slots('CAL')], 'QUI' => [$slots('QUI-A'), $slots('QUI-B')], 'FIS' => [$slots('FIS-A'), $slots('FIS-B')]]))->toBeTrue();
});

it('HC-03: sin combinación hoy, la del alumno tipo toma el primer grupo de cada materia por su clave (supuesto por confirmar)', function () {
    $state = States::builder()
        ->group(States::group('CAL', subject: 'CAL'))
        ->group(States::group('QUI-B', subject: 'QUI', code: 'B'))
        ->group(States::group('QUI-A', subject: 'QUI', code: 'A'))
        ->session('CAL', 1, '07:00', '09:00')
        ->session('QUI-A', 1, '08:00', '10:00')
        ->session('QUI-B', 1, '07:00', '08:00')
        ->build();

    expect(combinationCohortsOf($state, ['CAL', 'QUI-A', 'QUI-B']))->toBe([
        'CAL' => ['combination:P1:1:C2'],
        'QUI-A' => ['combination:P1:1:C2'],
        'QUI-B' => [],
    ]);
});

it('HC-03: si hoy no hay combinación solo por un grupo que se va a reprogramar, se elige como si no tuviera sesiones y no se fija una opción que choca con lo que no se mueve', function () {
    $state = States::builder()
        ->group(States::group('CAL', subject: 'CAL'))
        ->group(States::group('FIS', subject: 'FIS'))
        ->group(States::group('QUI-A', subject: 'QUI', code: 'A'))
        ->group(States::group('QUI-B', subject: 'QUI', code: 'B'))
        ->session('CAL', 1, '07:00', '10:00')
        ->session('FIS', 1, '09:00', '10:00')
        ->session('QUI-A', 1, '08:00', '10:00')
        ->session('QUI-B', 1, '07:00', '08:00')
        ->build();
    $ids = ['CAL', 'FIS', 'QUI-A', 'QUI-B'];

    expect(array_keys(array_filter(Cohorts::combinations($state, array_map(fn (string $id) => $state->groupOrFail($id), $ids), rescheduled: ['CAL']))))
        ->toBe(['CAL', 'FIS', 'QUI-B'])
        ->and(array_keys(array_filter(combinationCohortsOf($state, $ids))))->toBe(['CAL', 'FIS', 'QUI-A']);
});

it('HC-03: un semestre en que cada materia tiene un solo grupo no lleva combinación, y un grupo cancelado tampoco', function () {
    $state = States::builder()
        ->group(States::group('CAL', subject: 'CAL'))
        ->group(States::group('QUI', subject: 'QUI'))
        ->group(States::group('FIS-A', subject: 'FIS', semester: 2))
        ->group(States::group('FIS-B', subject: 'FIS', semester: 2, active: false))
        ->build();

    expect(combinationCohortsOf($state, ['CAL', 'QUI', 'FIS-A', 'FIS-B']))->toBe(['CAL' => [], 'QUI' => [], 'FIS-A' => [], 'FIS-B' => []]);
});

it('HC-03: la clave `combination:` no cuenta para SC-01 (sus grupos ya cuentan en `plan:`)', function () {
    expect(Cohorts::forStudentGaps(['plan:P1:1:C2', 'combination:P1:1:C2', 'package:PK:C2:1']))->toBe(['plan:P1:1:C2', 'package:PK:C2:1'])
        ->and(Cohorts::isCombination('combination:P1:1:C2'))->toBeTrue()
        ->and(Cohorts::isCombination('plan:P1:1:C2'))->toBeFalse();
});
