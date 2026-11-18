<?php

namespace App\Modules\Solver\Jobs;

use RuntimeException;

/**
 * SV-03: se pidió cancelar la corrida que corre. La lanza el aviso de avance de RunSolver para cortar al motor, y
 * RunSolver la atrapa y deja la corrida cancelada; nunca llega a failed().
 */
final class SolverRunCancelled extends RuntimeException {}
