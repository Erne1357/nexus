<?php

namespace App\Modules\Solver\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Models\SolverRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SV-03: cancela una corrida del motor (tarea 6.8; docs/dominio/reglas-de-negocio.md, «Corridas del motor»). Revisa el
 * estado de la fila bloqueada, no el del modelo que recibe:
 *
 * - en cola, pasa directo a cancelada; RunSolver ya no la toma;
 * - corriendo, guarda cuándo se pidió (`cancel_requested_at`) y RunSolver detiene al motor en su siguiente aviso de
 *   avance; pedirla otra vez no cambia nada;
 * - en otro estado, ValidationException en `run`.
 *
 * Deja bitácora («cancelled» o «cancel_requested»). No autoriza: quién cancela lo decide el componente que la llama con
 * SolverRunPolicy.
 */
final class CancelSolverRun
{
    /**
     * @throws ValidationException si la corrida ya no está en cola ni corriendo
     */
    public function handle(SolverRun $run, User $by): void
    {
        DB::transaction(function () use ($run, $by): void {
            $locked = SolverRun::query()->lockForUpdate()->findOrFail($run->getKey());

            if ($locked->status === SolverRunStatus::Running && $locked->cancel_requested_at !== null) {
                return;
            }

            $event = match ($locked->status) {
                SolverRunStatus::Queued => 'cancelled',
                SolverRunStatus::Running => 'cancel_requested',
                default => throw ValidationException::withMessages(['run' => 'Esta corrida ya no está en curso: no se puede cancelar.']),
            };

            $locked->forceFill([
                'cancel_requested_at' => now(),
                ...($event === 'cancelled' ? ['status' => SolverRunStatus::Cancelled, 'finished_at' => now()] : []),
            ])->save();

            activity()
                ->performedOn($locked)
                ->causedBy($by)
                ->event($event)
                ->withChanges(['attributes' => ['status' => $locked->status->value, 'cancel_requested_at' => $locked->cancel_requested_at->toIso8601String()]])
                ->log($event === 'cancelled' ? 'Canceló la corrida del motor en cola' : 'Pidió cancelar la corrida del motor que corre');
        });
    }
}
