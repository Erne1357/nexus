<?php

namespace App\Modules\Solver\Engines;

use InvalidArgumentException;

/**
 * Los motores disponibles (tarea 6.3). Los registra el provider de Solver con la etiqueta `solver.engines`: la heurística
 * en PHP (6.6) y el cliente de CP-SAT (fase 7) se agregan ahí. El registro vive en código y no en `settings`, porque el
 * catálogo de parámetros (CF-S7) no tiene parámetros de texto; elegir el motor predeterminado por modalidad
 * (`solver.default_engine`) llega con la 7.11. Mientras, el predeterminado es el último registrado.
 *
 * No guarda estado entre peticiones: solo indexa los motores que recibe.
 */
final readonly class EngineRegistry
{
    /** @var array<string, SolverEngine> */
    private array $engines;

    /**
     * @param  iterable<SolverEngine>  $engines
     */
    public function __construct(iterable $engines)
    {
        $byKey = [];

        foreach ($engines as $engine) {
            $byKey[$engine->key()] = $engine;
        }

        $this->engines = $byKey;
    }

    /**
     * @return list<SolverEngine>
     */
    public function all(): array
    {
        return array_values($this->engines);
    }

    /**
     * @throws InvalidArgumentException si no hay un motor con esa clave
     */
    public function get(string $key): SolverEngine
    {
        return $this->engines[$key] ?? throw new InvalidArgumentException("No hay un motor de horarios «{$key}».");
    }

    public function default(): SolverEngine
    {
        return $this->engines[array_key_last($this->engines)] ?? throw new InvalidArgumentException('No hay motores de horarios registrados.');
    }
}
