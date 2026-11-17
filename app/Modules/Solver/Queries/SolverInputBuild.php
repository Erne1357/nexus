<?php

namespace App\Modules\Solver\Queries;

use App\Modules\Solver\Contract\SolverInput;

/**
 * Lo que arma BuildSolverInput: la entrada del motor y los avisos para quien lanza la corrida (por ejemplo, el tronco
 * común que otro departamento todavía no programa). Los avisos no cambian la entrada ni bloquean la corrida.
 */
final readonly class SolverInputBuild
{
    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        public SolverInput $input,
        public array $warnings = [],
    ) {}
}
