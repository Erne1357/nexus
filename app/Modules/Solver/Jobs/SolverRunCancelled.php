<?php

namespace App\Modules\Solver\Jobs;

use RuntimeException;

/**
 * SV-03: hay que detener al motor porque se pidió cancelar la corrida o porque ya no está corriendo (se dio por perdida).
 * La lanza el aviso de avance de RunSolver para cortar al motor, y RunSolver la atrapa: deja cancelada la corrida que
 * siga corriendo y no toca la que ya no; nunca llega a failed().
 */
final class SolverRunCancelled extends RuntimeException {}
