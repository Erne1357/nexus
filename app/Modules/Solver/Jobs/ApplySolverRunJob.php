<?php

namespace App\Modules\Solver\Jobs;

use App\Modules\Core\Models\User;
use App\Modules\Solver\Actions\ApplySolverRun;
use App\Modules\Solver\Actions\ApplyTokenMismatch;
use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Models\SolverRun;
use App\Modules\Solver\Notifications\SolverRunApplied;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Escribe en la cola el horario de una corrida que aceptó el jefe (SV-05; tarea 6.10): RequestApplySolverRun la dejó en
 * `applying` con una ficha (`apply_token`) y despachó este job con ella. Un solo intento. Un job viejo, que encuentra la
 * corrida otra vez en `applying` con otra ficha (se liberó por vieja y se volvió a pedir), no la aplica ni la regresa:
 * revisa la ficha al leerla y ApplySolverRun la vuelve a revisar con la fila bloqueada (ApplyTokenMismatch).
 *
 * - Llama a ApplySolverRun, que bloquea la carga y revisa otra vez la vigencia (SV-02). No autoriza de nuevo: ya lo hizo
 *   la petición, y la policy `apply` pide `done` (supuesto por confirmar: un permiso quitado entre la petición y el job no
 *   lo detiene).
 * - Si termina bien, la corrida queda `applied` y la campana le avisa a quien aceptó con el resumen (SolverRunApplied).
 * - Si la entrada venció o el horario no se puede escribir, la corrida vuelve a `done` con el error en
 *   `metrics.apply_error` y se le avisa a quien aceptó; la pantalla ofrece «Volver a correr». Cualquier otra excepción
 *   llega a failed() y hace lo mismo.
 */
final class ApplySolverRunJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  string|null  $token  la ficha que RequestApplySolverRun puso al pasar la corrida a `applying`
     */
    public function __construct(public readonly string $runId, public readonly string $userId, public readonly ?string $token = null) {}

    public function handle(ApplySolverRun $apply): void
    {
        $run = SolverRun::query()->find($this->runId);
        $user = User::query()->find($this->userId);

        if ($run?->status !== SolverRunStatus::Applying || $run->apply_token !== $this->token) {
            return;
        }

        if ($user === null) {
            $this->revert('Quien aceptó la corrida ya no existe.');

            return;
        }

        try {
            $summary = $apply->handle($run, $user);
        } catch (ApplyTokenMismatch) {
            return;
        } catch (ValidationException $exception) {
            $this->revert((string) collect($exception->errors())->flatten()->first(), $user);

            return;
        }

        $user->notify(new SolverRunApplied($run->refresh(), $summary));
    }

    public function failed(?Throwable $exception): void
    {
        $message = trim((string) $exception?->getMessage());

        $this->revert($message === '' ? 'La corrida no se pudo aplicar.' : $message, User::query()->find($this->userId));
    }

    /**
     * Regresa a `done`, con el error guardado, una corrida que sigue aplicándose, y avisa a quien la aceptó. No toca una
     * que ya cambió de estado. Con `$before`, solo si no se movió desde entonces (LaunchSolverRun da por perdido un
     * job que no terminó); con `$token`, solo si se está aplicando con esa ficha.
     */
    public static function release(string $runId, string $message, ?CarbonInterface $before = null, ?User $notify = null, ?string $token = null): bool
    {
        $released = DB::transaction(function () use ($runId, $message, $before, $token): ?SolverRun {
            $run = SolverRun::query()->lockForUpdate()->find($runId);

            if ($run?->status !== SolverRunStatus::Applying || ($before !== null && ! $run->updated_at->lt($before)) || ($token !== null && $run->apply_token !== $token)) {
                return null;
            }

            $run->forceFill([
                'status' => SolverRunStatus::Done,
                'metrics' => [...($run->metrics ?? []), 'apply_error' => Str::limit($message, 1000)],
            ])->save();

            return $run;
        });

        if ($released !== null) {
            $notify?->notify(new SolverRunApplied($released, null, $message));
        }

        return $released !== null;
    }

    private function revert(string $message, ?User $user = null): void
    {
        self::release($this->runId, $message, null, $user, $this->token ?? '');
    }
}
