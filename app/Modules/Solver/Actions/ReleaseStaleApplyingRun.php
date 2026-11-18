<?php

namespace App\Modules\Solver\Actions;

use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Jobs\ApplySolverRunJob;
use App\Modules\Solver\Jobs\RunSolver;
use App\Modules\Solver\Models\SolverRun;

/**
 * Regresa a `done` una corrida que lleva más de RunSolver::STALE_AFTER segundos en `applying` sin moverse (su job se
 * perdió), con el error «No terminó de aplicarse: vuelve a aceptarla.» en `metrics.apply_error` (SV-05; revisión del
 * Riel A en #594). Es la misma liberación que hace LaunchSolverRun con las corridas viejas (ApplySolverRunJob::release,
 * con la fila bloqueada y solo si no se movió desde entonces); la usa la pantalla de corridas al abrir una. Sin aviso
 * en la campana: quien la abre ya lo ve.
 */
final class ReleaseStaleApplyingRun
{
    public const string MESSAGE = 'No terminó de aplicarse: vuelve a aceptarla.';

    /**
     * Si la regresó a `done`.
     */
    public function handle(SolverRun $run): bool
    {
        if ($run->status !== SolverRunStatus::Applying) {
            return false;
        }

        return ApplySolverRunJob::release($run->getKey(), self::MESSAGE, now()->subSeconds(RunSolver::STALE_AFTER));
    }
}
