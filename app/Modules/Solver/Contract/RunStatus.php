<?php

namespace App\Modules\Solver\Contract;

/** El estado con el que un motor cierra una corrida (ver «Salida» en docs/arquitectura/solver.md). */
enum RunStatus: string
{
    case Optimal = 'optimal';
    case Feasible = 'feasible';
    case Partial = 'partial';
    case Infeasible = 'infeasible';
    case Error = 'error';
}
