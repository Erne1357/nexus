<?php

use App\Modules\Core\Settings\SettingRegistry;
use App\Modules\Solver\Engines\EngineRegistry;
use App\Modules\Solver\Engines\FixedEngine;

/*
| El registro de motores (tarea 6.3): los motores se registran en el provider de Solver con la etiqueta
| `solver.engines`; hoy, el de prueba.
*/

it('lista los motores registrados y da uno por su clave', function () {
    $registry = app(EngineRegistry::class);

    expect(array_map(fn ($engine): string => $engine->key(), $registry->all()))->toContain(FixedEngine::KEY)
        ->and($registry->get(FixedEngine::KEY))->toBeInstanceOf(FixedEngine::class)
        ->and($registry->default())->toBeInstanceOf(FixedEngine::class);
});

it('rechaza un motor que no existe', function () {
    expect(fn () => app(EngineRegistry::class)->get('magia'))->toThrow(InvalidArgumentException::class, 'magia');
});

it('el parámetro de tiempos aparece en el catálogo de Parámetros, en Motor de horarios', function () {
    expect(app(SettingRegistry::class)->get('solver.time_limits')->moduleLabel)->toBe('Motor de horarios');
});
