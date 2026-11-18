<?php

use App\Modules\Scheduling\Rules\Cohorts;
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

    expect(cohortsOf($state, ['A', 'B']))->toBe(['A' => ['plan:P1:1:C2', 'package:PK:1'], 'B' => ['plan:P1:1:C2', 'package:PK:1']]);
});

it('HC-03: dos instancias del mismo paquete son cohortes distintas', function () {
    $state = States::builder()
        ->group(States::group('A1', subject: 'CAL', package: 'PK', packageNumber: 1))
        ->group(States::group('A2', subject: 'CAL', package: 'PK', packageNumber: 2))
        ->build();

    // La materia tiene dos grupos: no hay cohorte de plan, solo la de cada instancia.
    expect(cohortsOf($state, ['A1', 'A2']))->toBe(['A1' => ['package:PK:1'], 'A2' => ['package:PK:2']]);
});

it('HC-03: un grupo cancelado con paquete no lleva la cohorte del paquete, y sin paquete no cambia nada', function () {
    $state = States::builder()
        ->group(States::group('A', subject: 'CAL', active: false, package: 'PK', packageNumber: 1))
        ->group(States::group('B', subject: 'QUI'))
        ->build();

    expect(cohortsOf($state, ['A', 'B']))->toBe(['A' => [], 'B' => ['plan:P1:1:C2']]);
});
