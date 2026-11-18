<?php

use App\Modules\Scheduling\Rules\Soft\SoftContext;
use App\Modules\Scheduling\Rules\Soft\SoftScorer;
use App\Modules\Scheduling\Validation\ValidationScope;
use Tests\Support\Scheduling\States;

/*
| SoftScorer (tarea 6.5): suma las reglas blandas SC-01 a SC-09 con los pesos del contrato del motor y da el total y el
| desglose por regla. Menor es mejor. El escenario está calculado a mano.
*/

/**
 * A y B del mismo docente y de la misma cohorte. Lunes: A 7-8 en E1 y B 10-11 en E2 (2 h de hueco del alumno tipo y 2
 * del docente). Martes: A 7-9 (5 h en 5 días: 1 h de más) en E1 y B 9-10 en E2 (un cambio de edificio). El salón R2
 * no es el por defecto de la materia de B: sus 2 h cuentan para SC-03.
 */
function softScorerState(): array
{
    $state = States::builder()
        ->group(States::group('A', teacher: 'T1', subject: 'S5'))->group(States::group('B', teacher: 'T1', subject: 'S2'))
        ->subject(States::subject('S5', theory: 3, practice: 2))->subject(States::subject('S2', theory: 2, practice: 0))
        ->teacher(States::teacher('T1'))
        ->session('A', 1, '07:00', '08:00', 'R1')->session('B', 1, '10:00', '11:00', 'R2')
        ->session('A', 2, '07:00', '09:00', 'R1')->session('B', 2, '09:00', '10:00', 'R2')
        ->build();
    $context = new SoftContext(
        cohorts: ['A' => ['plan:P1:1:C2'], 'B' => ['plan:P1:1:C2']],
        buildings: ['R1' => 'E1', 'R2' => 'E2'],
        roomDefaults: ['S2|C2' => ['R1' => 1]],
    );

    return [$state, $context];
}

it('suma cada regla por su peso y da el total y el desglose de SC-01 a SC-09', function () {
    [$state, $context] = softScorerState();

    $result = (new SoftScorer)->score($state, ValidationScope::groups('A', 'B'), $context, [
        'SC-01' => 10, 'SC-02' => 6, 'SC-03' => 5, 'SC-04' => 4, 'SC-05' => 3, 'SC-06' => 2, 'SC-07' => 3, 'SC-08' => 2, 'SC-09' => 2,
    ]);

    expect($result->units)->toBe(['SC-01' => 2, 'SC-02' => 1, 'SC-03' => 2, 'SC-04' => 0, 'SC-05' => 2, 'SC-06' => 1, 'SC-07' => 0, 'SC-08' => 0, 'SC-09' => 0])
        ->and($result->byRule)->toBe(['SC-01' => 20, 'SC-02' => 6, 'SC-03' => 10, 'SC-04' => 0, 'SC-05' => 6, 'SC-06' => 2, 'SC-07' => 0, 'SC-08' => 0, 'SC-09' => 0])
        ->and($result->total)->toBe(44);
});

it('una regla con peso cero, o sin peso, no se calcula; un peso de otra regla se ignora', function () {
    [$state, $context] = softScorerState();

    $result = (new SoftScorer)->score($state, ValidationScope::groups('A', 'B'), $context, ['SC-01' => 1, 'SC-02' => 0, 'HC-01' => 100]);

    expect($result->units)->toBe(['SC-01' => 2, 'SC-02' => 0, 'SC-03' => 0, 'SC-04' => 0, 'SC-05' => 0, 'SC-06' => 0, 'SC-07' => 0, 'SC-08' => 0, 'SC-09' => 0])
        ->and($result->total)->toBe(2)
        ->and(array_keys($result->byRule))->toBe(array_keys(SoftScorer::RULES))
        ->and(array_map(fn (string $rule): string => (new $rule)->rule(), SoftScorer::RULES))->toBe(array_combine(array_keys(SoftScorer::RULES), array_keys(SoftScorer::RULES)));
});

it('un horario mejor da un puntaje menor', function () {
    [$state, $context] = softScorerState();
    $weights = ['SC-01' => 10, 'SC-05' => 3];
    $compact = States::builder()
        ->group(States::group('A', teacher: 'T1'))->group(States::group('B', teacher: 'T1'))->teacher(States::teacher('T1'))
        ->session('A', 1, '07:00', '08:00')->session('B', 1, '08:00', '09:00')
        ->build();

    expect((new SoftScorer)->score($compact, ValidationScope::groups('A', 'B'), $context, $weights)->total)->toBe(0)
        ->and((new SoftScorer)->score($state, ValidationScope::groups('A', 'B'), $context, $weights)->total)->toBe(26);
});
