<?php

namespace App\Modules\Solver\Engines\PhpHeuristic;

use App\Modules\Solver\Contract\ProposedSession;

/**
 * Lo que deja la búsqueda local (tarea 6.7), antes de la validación final con el evaluador.
 */
final readonly class SearchResult
{
    /**
     * @param  array<string, list<ProposedSession>>  $sessions  por grupo colocado
     * @param  int  $iterations  los grupos revisados (una iteración es revisar todos los movimientos de un grupo)
     * @param  int  $evaluated  los movimientos que caben y se puntuaron
     * @param  array{move: int, swap: int, room: int, shift: int}  $accepted  los movimientos aceptados, por tipo
     * @param  int  $initialScore  el puntaje blando al empezar
     * @param  int  $finalScore  el puntaje blando al terminar (nunca mayor que el inicial)
     * @param  bool  $timedOut  si paró por el tiempo límite y no porque dejó de mejorar
     * @param  int  $idleLimit  las iteraciones seguidas sin mejora con que para
     */
    public function __construct(
        public array $sessions,
        public int $iterations,
        public int $evaluated,
        public array $accepted,
        public int $initialScore,
        public int $finalScore,
        public bool $timedOut,
        public int $idleLimit,
    ) {}

    public function acceptedMoves(): int
    {
        return array_sum($this->accepted);
    }
}
