<?php

namespace App\Modules\Solver\Engines\PhpHeuristic;

use App\Modules\Solver\Contract\ProposedSession;

/**
 * Lo que deja la construcción voraz (tarea 6.6), antes de la validación final con el evaluador.
 */
final readonly class BuildResult
{
    /**
     * @param  array<string, list<ProposedSession>>  $sessions  por grupo colocado
     * @param  array<string, list<string>>  $unscheduled  los motivos, por grupo que no cupo
     * @param  array<string, int>  $placementOrder  en qué turno se colocó cada grupo (0 el primero)
     * @param  list<string>  $overloadedTeachers  docentes cuyos grupos movibles suman más de lo que les queda (HC-08)
     */
    public function __construct(
        public array $sessions,
        public array $unscheduled,
        public array $placementOrder,
        public int $candidates,
        public bool $timedOut,
        public int $otherDays,
        public int $doubleHours,
        public array $overloadedTeachers,
    ) {}
}
