<?php

namespace App\Modules\Solver\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Jobs\RunSolver;
use App\Modules\Solver\Models\SolverRun;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * SV-03: cancela una corrida del motor (tarea 6.8; docs/dominio/reglas-de-negocio.md, «Corridas del motor»). Revisa el
 * estado de la fila bloqueada, no el del modelo que recibe:
 *
 * - en cola, pasa directo a cancelada; RunSolver ya no la toma;
 * - corriendo, guarda cuándo se pidió (`cancel_requested_at`) y RunSolver detiene al motor en su siguiente aviso de
 *   avance; pedirla otra vez no cambia nada. Si ya no se mueve desde hace RunSolver::STALE_AFTER segundos (su job murió),
 *   pasa directo a cancelada; si el job sigue vivo, su guardado final ve que ya no corre y no la pisa;
 * - en otro estado, ValidationException en `run`.
 *
 * Deja bitácora («cancelled» o «cancel_requested»). Autoriza a `$by` con SolverRunPolicy::cancel (quien la lanzó o quien
 * puede lanzar ese alcance): una AuthorizationException (403) si no.
 */
final class CancelSolverRun
{
    /**
     * @throws AuthorizationException si `$by` no puede cancelarla
     * @throws ValidationException si la corrida ya no está en cola ni corriendo
     */
    public function handle(SolverRun $run, User $by): void
    {
        Gate::forUser($by)->authorize('cancel', $run);

        DB::transaction(function () use ($run, $by): void {
            $locked = SolverRun::query()->lockForUpdate()->findOrFail($run->getKey());
            $stalled = $locked->updated_at->lt(now()->subSeconds(RunSolver::STALE_AFTER));

            if ($locked->status === SolverRunStatus::Running && $locked->cancel_requested_at !== null && ! $stalled) {
                return;
            }

            $event = match ($locked->status) {
                SolverRunStatus::Queued => 'cancelled',
                SolverRunStatus::Running => $stalled ? 'cancelled' : 'cancel_requested',
                default => throw ValidationException::withMessages(['run' => 'Esta corrida ya no está en curso: no se puede cancelar.']),
            };

            $locked->forceFill([
                'cancel_requested_at' => $locked->cancel_requested_at ?? now(),
                ...($event === 'cancelled' ? ['status' => SolverRunStatus::Cancelled, 'finished_at' => now()] : []),
            ])->save();

            activity()
                ->performedOn($locked)
                ->causedBy($by)
                ->event($event)
                ->withChanges(['attributes' => ['status' => $locked->status->value, 'cancel_requested_at' => $locked->cancel_requested_at->toIso8601String()]])
                ->log($event === 'cancelled' ? 'Canceló la corrida del motor' : 'Pidió cancelar la corrida del motor que corre');
        });
    }
}
