<?php

namespace App\Modules\Solver\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Models\SolverRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * SV-07: descarta una corrida terminada (tarea 6.10; docs/dominio/reglas-de-negocio.md, «Corridas del motor»). Autoriza
 * a `$by` con SolverRunPolicy::discard (quien la lanzó o quien puede lanzar ese alcance: el jefe, las de su carga; el
 * coordinador, las de su carrera). Revisa el estado de la fila bloqueada: solo una terminada pasa a descartada; ya no
 * se puede aceptar. No toca ninguna sesión. Deja bitácora («discarded»).
 */
final class DiscardSolverRun
{
    /**
     * @throws ValidationException si la corrida no está terminada
     */
    public function handle(SolverRun $run, User $by): void
    {
        Gate::forUser($by)->authorize('discard', $run);

        DB::transaction(function () use ($run, $by): void {
            $locked = SolverRun::query()->lockForUpdate()->findOrFail($run->getKey());

            if ($locked->status !== SolverRunStatus::Done) {
                throw ValidationException::withMessages(['run' => 'Solo se descarta una corrida terminada.']);
            }

            $locked->forceFill(['status' => SolverRunStatus::Discarded])->save();

            activity()
                ->performedOn($locked)
                ->causedBy($by)
                ->event('discarded')
                ->withChanges(['attributes' => ['status' => SolverRunStatus::Discarded->value], 'old' => ['status' => SolverRunStatus::Done->value]])
                ->log('Descartó la corrida del motor');
        });
    }
}
