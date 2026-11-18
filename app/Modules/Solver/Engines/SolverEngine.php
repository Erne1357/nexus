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
 *
 * Por eso el motor debe llamar a `$progress` con regularidad mientras busca (por ejemplo, en cada iteración o cada
 * solución intermedia): es la única forma en que se entera de una cancelación (SV-03). El job decide cuándo revisar (a
 * lo más una vez por segundo) y corta lanzando una excepción desde `$progress`; el motor la deja salir y no reintenta.
 * Un motor que nunca llama a `$progress` solo se detiene al vencer su `time_limit_seconds`.
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
     * Si el motor revisa las reglas duras (HC-01 a HC-13) al proponer. Solo a esos motores la suite de fixtures les exige
     * resultados correctos (grupos programados, infactibilidad reportada); a los demás, como el de prueba, solo la
     * forma de la salida y que quede dentro de la rejilla de cada campus.
     */
    public function checksHardRules(): bool;

    /**
     * Resuelve la entrada dentro de `time_limit_seconds` y devuelve la mejor salida encontrada, con un `status` honesto.
     *
     * @param  (Closure(int $percent, ?int $bestScore): void)|null  $progress  avance de 0 a 100 y mejor puntaje blando
     *                                                                         hasta el momento, para `wire:poll`
     */
    public function solve(SolverInput $input, ?Closure $progress = null): SolverOutput;
}
