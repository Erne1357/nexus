<?php

namespace App\Modules\Solver\Engines;

use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Contract\SolverOutput;
use Closure;

/**
 * Un motor de horarios (tarea 6.3; docs/arquitectura/solver.md, «Interfaz SolverEngine»): recibe la entrada del contrato
 * y devuelve la salida, reportando su avance. La heurística en PHP (6.6 y 6.7) y el cliente de CP-SAT (fase 7) la
 * implementan por igual, y la pantalla de corridas no distingue entre ellos.
 *
 * Una implementación no guarda estado entre corridas (Octane): todo lo de una corrida vive en la llamada a solve().
 * Cancelar una corrida en curso lo resuelve quien la lanza (el job de la 6.8), no el motor.
 */
interface SolverEngine
{
    /**
     * La clave con la que se elige el motor (`fixed`, `php_heuristic`, `cpsat`); va en `solver_runs.engine` y en
     * `metrics.engine`.
     */
    public function key(): string;

    /**
     * El nombre del motor en la pantalla, en español.
     */
    public function label(): string;

    /**
     * Las versiones del contrato que entiende. Una entrada de otra versión devuelve `status = error`.
     *
     * @return list<int>
     */
    public function supportedVersions(): array;

    /**
     * Resuelve la entrada dentro de `time_limit_seconds` y devuelve la mejor salida encontrada, con un `status` honesto.
     *
     * @param  (Closure(int $percent, ?int $bestScore): void)|null  $progress  avance de 0 a 100 y mejor puntaje blando
     *                                                                         hasta el momento, para `wire:poll`
     */
    public function solve(SolverInput $input, ?Closure $progress = null): SolverOutput;
}
