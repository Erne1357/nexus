<?php

namespace App\Modules\Solver\Actions;

use RuntimeException;

/**
 * La corrida se está aplicando con otra ficha (SV-05; `solver_runs.apply_token`): la pidió otra aceptación después de
 * la que despachó este job. La lanza ApplySolverRun con la corrida bloqueada, y ApplySolverRunJob la atrapa y regresa
 * sin hacer nada; nunca llega a la pantalla.
 */
final class ApplyTokenMismatch extends RuntimeException {}
