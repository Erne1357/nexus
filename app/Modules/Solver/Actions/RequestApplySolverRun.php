<?php

namespace App\Modules\Solver\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Jobs\ApplySolverRunJob;
use App\Modules\Solver\Models\SolverRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Pide aceptar una corrida (SV-05; tarea 6.10): escribir el horario tarda más de un segundo, así que lo hace un job en
 * cola (ApplySolverRunJob; CLAUDE.md §4.7). Esta Action es lo que corre en la petición:
 *
 * 1. Revisa que sea una corrida de departamento terminada y autoriza `apply` a `$by` (el jefe de la carga).
 * 2. Revisa la vigencia (SV-02) con ApplySolverRun::isCurrent: si la carga cambió, lanza ya el mensaje de vencida.
 * 3. Pasa la corrida de `done` a `applying` con una actualización condicional (dos peticiones no despachan dos jobs) y
 *    quita el error de un intento anterior; despacha el job. Si el despacho falla, la corrida vuelve a `done` y la
 *    excepción se vuelve a lanzar.
 *
 * Mientras está `applying` no se lanza otra corrida del departamento (SV-01).
 */
final class RequestApplySolverRun
{
    public function __construct(private readonly ApplySolverRun $apply) {}

    /**
     * @throws ValidationException si no se puede aceptar o la entrada ya no está vigente (`run`)
     */
    public function handle(SolverRun $run, User $by): void
    {
        ApplySolverRun::ensureAppliable($run, SolverRunStatus::Done);
        Gate::forUser($by)->authorize('apply', $run);

        if (! $this->apply->isCurrent($run)) {
            throw ValidationException::withMessages(['run' => ApplySolverRun::STALE_MESSAGE]);
        }

        $claimed = SolverRun::query()
            ->whereKey($run->getKey())
            ->where('status', SolverRunStatus::Done)
            ->update(['status' => SolverRunStatus::Applying, 'metrics' => DB::raw("metrics - 'apply_error'")]);

        if ($claimed === 0) {
            throw ValidationException::withMessages(['run' => 'Solo se acepta una corrida terminada.']);
        }

        try {
            ApplySolverRunJob::dispatch($run->getKey(), $by->getKey());
        } catch (Throwable $exception) {
            SolverRun::query()->whereKey($run->getKey())->where('status', SolverRunStatus::Applying)->update(['status' => SolverRunStatus::Done]);

            throw $exception;
        }
    }
}
