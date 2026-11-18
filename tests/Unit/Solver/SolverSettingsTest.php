<?php

use App\Modules\Solver\Enums\RunScope;
use App\Modules\Solver\Enums\SolverPermission;
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

it('sin parámetro los pesos blandos son los de la tabla SC y salen con la llave SC-01', function () {
    expect(SolverSettings::fromValue(null, null)->softWeightsByRule())->toBe(['SC-01' => 10, 'SC-02' => 6, 'SC-03' => 5, 'SC-04' => 4, 'SC-05' => 3, 'SC-06' => 2, 'SC-07' => 3, 'SC-08' => 2, 'SC-09' => 2]);
});

it('lee los pesos guardados y usa el de omisión en los que faltan o no son enteros de 0 a 100', function () {
    $settings = SolverSettings::fromValue(null, ['sc_01' => 0, 'sc_02' => 100, 'sc_03' => 101, 'sc_04' => -1, 'sc_05' => '7', 'sc_06' => 2.5, 'sc_09' => 20]);

    expect($settings->softWeightsByRule())->toBe(['SC-01' => 0, 'SC-02' => 100, 'SC-03' => 5, 'SC-04' => 4, 'SC-05' => 3, 'SC-06' => 2, 'SC-07' => 3, 'SC-08' => 2, 'SC-09' => 20]);
});

it('declara los pesos blandos con un campo por regla, permiso de Subdirección y los mismos valores de omisión', function () {
    $definition = collect(SolverSettings::definitions())->firstWhere('key', SolverSettings::SOFT_WEIGHTS_SETTING);

    expect(array_keys($definition->fields))->toBe(array_keys(SolverSettings::DEFAULT_SOFT_WEIGHTS))
        ->and($definition->permission)->toBe(SolverPermission::SettingUpdate)
        ->and(SolverSettings::fromValue(null, $definition->default))->toEqual(new SolverSettings);
});

it('un tiempo fuera de 10 a 600 segundos usa el de omisión, como lo valida el lanzamiento (SV-02)', function (int $seconds) {
    expect(SolverSettings::fromValue(['department' => $seconds])->timeLimitFor(RunScope::Department))->toBe(300);
})->with([5, 9, 601, 3600]);
