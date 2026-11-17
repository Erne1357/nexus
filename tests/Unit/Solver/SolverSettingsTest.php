<?php

use App\Modules\Solver\Enums\RunScope;
use App\Modules\Solver\Rules\SolverSettings;

/*
| El tiempo máximo de una corrida por alcance (`solver.time_limits`; docs/arquitectura/solver.md): 300 segundos por
| departamento y 60 por carrera; lo que falta o no es válido usa el de omisión.
*/

it('sin parámetro usa 300 segundos por departamento y 60 por carrera', function () {
    $settings = SolverSettings::fromValue(null);

    expect($settings->timeLimitFor(RunScope::Department))->toBe(300)
        ->and($settings->timeLimitFor(RunScope::Career))->toBe(60);
});

it('lee los tiempos guardados y descarta los que no son enteros positivos', function () {
    expect(SolverSettings::fromValue(['department' => 600, 'career' => 0]))->toEqual(new SolverSettings(600, 60))
        ->and(SolverSettings::fromValue(['department' => '600']))->toEqual(new SolverSettings);
});

it('declara el parámetro con los mismos valores de omisión que usa al leerlo', function () {
    $definition = SolverSettings::definitions()[0];

    expect($definition->key)->toBe(SolverSettings::TIME_LIMITS_SETTING)
        ->and(SolverSettings::fromValue($definition->default))->toEqual(new SolverSettings);
});
