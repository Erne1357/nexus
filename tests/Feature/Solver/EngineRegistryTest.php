<?php

use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Settings\SettingRegistry;
use App\Modules\Solver\Engines\CpSat\CpSatClient;
use App\Modules\Solver\Engines\CpSat\CpSatEngine;
use App\Modules\Solver\Engines\EngineRegistry;
use App\Modules\Solver\Engines\FixedEngine;
use App\Modules\Solver\Engines\PhpHeuristic\PhpHeuristicEngine;

/*
| El registro de motores (tarea 6.3): los motores se registran en el provider de Solver con la etiqueta
| `solver.engines`: el de prueba y la heurística en PHP (6.6). El predeterminado de una modalidad es el de
| `solver.default_engine` (7.11), con la heurística de fábrica.
*/

it('lista los motores registrados y da uno por su clave', function () {
    $registry = app(EngineRegistry::class);

    expect(array_map(fn ($engine): string => $engine->key(), $registry->all()))->toBe([FixedEngine::KEY, PhpHeuristicEngine::KEY])
        ->and($registry->get(FixedEngine::KEY))->toBeInstanceOf(FixedEngine::class)
        ->and($registry->get(PhpHeuristicEngine::KEY))->toBeInstanceOf(PhpHeuristicEngine::class)
        ->and($registry->default())->toBeInstanceOf(PhpHeuristicEngine::class);
});

it('rechaza un motor que no existe', function () {
    expect(fn () => app(EngineRegistry::class)->get('magia'))->toThrow(InvalidArgumentException::class, 'magia');
});

it('el parámetro de tiempos aparece en el catálogo de Parámetros, en Motor de horarios', function () {
    expect(app(SettingRegistry::class)->get('solver.time_limits')->moduleLabel)->toBe('Motor de horarios');
});

/**
 * Un registro con los tres motores, como en un servidor con el servicio de CP-SAT.
 */
function registryWithCpSat(): EngineRegistry
{
    return new EngineRegistry([new CpSatEngine(new CpSatClient('http://solver.test', 'secreto')), new FixedEngine, new PhpHeuristicEngine]);
}

it('7.11: el predeterminado de una modalidad es el de `solver.default_engine`, y sin parámetro la heurística', function () {
    $registry = registryWithCpSat();

    expect($registry->default('PRES'))->toBeInstanceOf(PhpHeuristicEngine::class)
        ->and($registry->default())->toBeInstanceOf(PhpHeuristicEngine::class);

    Setting::query()->create(['scope' => SettingScope::Global, 'scope_id' => null, 'key' => 'solver.default_engine', 'value' => ['pres' => 'cpsat', 'ead' => 'heuristic', 'enl' => 'heuristic', 'tnmv' => 'heuristic']]);

    expect($registry->default('PRES'))->toBeInstanceOf(CpSatEngine::class)
        ->and($registry->default('EAD'))->toBeInstanceOf(PhpHeuristicEngine::class)
        ->and($registry->default())->toBeInstanceOf(PhpHeuristicEngine::class);
});

it('7.11: si el motor elegido no está registrado (CP-SAT sin servicio), el predeterminado es la heurística', function () {
    Setting::query()->create(['scope' => SettingScope::Global, 'scope_id' => null, 'key' => 'solver.default_engine', 'value' => ['pres' => 'cpsat', 'ead' => 'heuristic', 'enl' => 'heuristic', 'tnmv' => 'heuristic']]);

    expect(app(EngineRegistry::class)->default('PRES'))->toBeInstanceOf(PhpHeuristicEngine::class)
        ->and((new EngineRegistry([new FixedEngine]))->default('PRES'))->toBeInstanceOf(FixedEngine::class);
});

it('7.11: el motor predeterminado aparece en Parámetros, en Motor de horarios', function () {
    expect(app(SettingRegistry::class)->get('solver.default_engine')->moduleLabel)->toBe('Motor de horarios');
});
