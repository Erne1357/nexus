<?php

namespace App\Modules\Solver\Engines;

use App\Modules\Solver\Rules\SolverSettings;
use InvalidArgumentException;

/**
 * Los motores disponibles (tarea 6.3). Los registra el provider de Solver con la etiqueta `solver.engines`: la heurística
 * en PHP (6.6) y el cliente de CP-SAT (fase 7) se agregan ahí. El registro vive en código; el motor predeterminado de
 * cada modalidad es el parámetro `solver.default_engine` (7.11, SolverSettings): de fábrica, CP-SAT en Presencial y la
 * heurística en las demás.
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

    /**
     * El motor que se propone al lanzar en la modalidad (`PRES`): el de `solver.default_engine` si está registrado (CP-SAT
     * solo lo está con el servicio configurado); si no, o sin modalidad, la heurística; y si tampoco está, el último
     * registrado. Con modalidad lee el parámetro de la base.
     *
     * @throws InvalidArgumentException si no hay motores registrados
     */
    public function default(?string $modalityCode = null): SolverEngine
    {
        $preferred = $modalityCode === null ? SolverSettings::DEFAULT_ENGINE : SolverSettings::fromSettings()->defaultEngineFor($modalityCode);

        return $this->engines[$preferred]
            ?? $this->engines[SolverSettings::DEFAULT_ENGINE]
            ?? ($this->engines === [] ? throw new InvalidArgumentException('No hay motores de horarios registrados.') : $this->engines[array_key_last($this->engines)]);
    }
}
