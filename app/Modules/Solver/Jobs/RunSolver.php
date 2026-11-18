<?php

namespace App\Modules\Solver\Jobs;

use App\Modules\Solver\Actions\LaunchSolverRun;
use App\Modules\Solver\Contract\ProposedSession;
use App\Modules\Solver\Contract\RunStatus;
use App\Modules\Solver\Contract\SolverOutput;
use App\Modules\Solver\Engines\EngineRegistry;
use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Models\SolverRun;
use App\Modules\Solver\Notifications\SolverRunFinished;
use App\Modules\Solver\Support\RunPayloads;
use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Corre en la cola una corrida que lanzó LaunchSolverRun (tarea 6.8; docs/arquitectura/solver.md, «Ciclo de vida»).
 *
 * - Solo corre una corrida que sigue en cola, y la toma con una actualización condicional a `running`: un job repetido
 *   o el de una corrida cancelada no hace nada.
 * - Lee la entrada (RunPayloads), llama al motor de la corrida y le pasa un aviso de avance que guarda `progress` y
 *   `best_score` a lo más una vez por PROGRESS_INTERVAL_MS. En esa misma revisión, si se pidió cancelar (SV-03), lanza
 *   SolverRunCancelled, que corta al motor y deja la corrida cancelada, sin salida y sin aviso. Si el motor termina antes
 *   de notar la cancelación, la corrida queda terminada con su salida (supuesto por confirmar).
 * - Al terminar guarda la salida, validada contra el contrato, en la fila o en archivo; las métricas del motor con un
 *   resumen (`result`, `sessions`, `changed_sessions`, `unscheduled`, `hard_violations`, `soft_score`) para la
 *   pantalla y el aviso sin leer la salida, y la deja terminada aunque el motor diga `partial` o `infeasible`: la
 *   pantalla decide qué mostrar. Si el motor responde `error`, queda fallida con su salida y el primer renglón del log.
 * - Si algo lanza, failed() la deja fallida con el mensaje, sin la traza.
 * - Terminada o fallida, avisa en la campana a quien la lanzó (SolverRunFinished).
 *
 * Un solo intento: si falla, se vuelve a lanzar. `timeout` es el límite de la corrida más TIMEOUT_MARGIN, por debajo
 * del `retry_after` de la conexión (config/queue.php), y al agotarse la corrida queda fallida.
 */
final class RunSolver implements ShouldQueue
{
    use Queueable;

    public const int TIMEOUT_MARGIN = 60;

    public const int PROGRESS_INTERVAL_MS = 1000;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $runId, int $timeLimitSeconds = LaunchSolverRun::MAX_TIME_LIMIT)
    {
        $this->timeout = $timeLimitSeconds + self::TIMEOUT_MARGIN;
    }

    public function handle(EngineRegistry $engines): void
    {
        $claimed = SolverRun::query()
            ->whereKey($this->runId)
            ->where('status', SolverRunStatus::Queued)
            ->update(['status' => SolverRunStatus::Running, 'started_at' => now(), 'progress' => 0]);

        if ($claimed === 0) {
            return;
        }

        $run = SolverRun::query()->findOrFail($this->runId);
        $engine = $engines->get($run->engine);

        try {
            $output = $engine->solve(RunPayloads::input($run), $this->progress($run->getKey()));
        } catch (SolverRunCancelled) {
            SolverRun::query()
                ->whereKey($run->getKey())
                ->where('status', SolverRunStatus::Running)
                ->update(['status' => SolverRunStatus::Cancelled, 'finished_at' => now()]);

            return;
        }

        $output = SolverOutput::fromArray($output->toArray());
        $failed = $output->status === RunStatus::Error;

        $run->refresh()->forceFill([
            ...RunPayloads::attributes($run->getKey(), 'output', $output->toArray()),
            'metrics' => $this->metrics($output),
            'status' => $failed ? SolverRunStatus::Failed : SolverRunStatus::Done,
            'error' => $failed ? Str::limit($output->log[0] ?? 'El motor terminó con error.', 1000) : null,
            'progress' => 100,
            'best_score' => $output->softScore->total,
            'finished_at' => now(),
        ])->save();

        $run->creator?->notify(new SolverRunFinished($run));
    }

    /**
     * Deja fallida la corrida que corría (o que no llegó a correr) con el mensaje del error, y avisa a quien la lanzó.
     * Una que ya no está en curso no se toca.
     */
    public function failed(?Throwable $exception): void
    {
        $run = SolverRun::query()->whereKey($this->runId)->first();

        if ($run === null || ! $run->status->isInProgress()) {
            return;
        }

        $message = trim((string) $exception?->getMessage());

        $run->forceFill([
            'status' => SolverRunStatus::Failed,
            'finished_at' => now(),
            'error' => Str::limit($message === '' ? 'La corrida no terminó.' : $message, 1000),
        ])->save();

        $run->creator?->notify(new SolverRunFinished($run));
    }

    /**
     * El aviso de avance para el motor: guarda el avance (de 0 a 100) y el mejor puntaje, si lo trae, y revisa si se
     * pidió cancelar, a lo más una vez por PROGRESS_INTERVAL_MS.
     *
     * @return Closure(int, ?int): void
     */
    private function progress(string $runId): Closure
    {
        $lastCheck = null;

        return function (int $percent, ?int $bestScore) use ($runId, &$lastCheck): void {
            $now = now()->getTimestampMs();

            if ($lastCheck !== null && $now - $lastCheck < self::PROGRESS_INTERVAL_MS) {
                return;
            }

            $lastCheck = $now;

            SolverRun::query()->whereKey($runId)->update([
                'progress' => max(0, min(100, $percent)),
                ...($bestScore === null ? [] : ['best_score' => $bestScore]),
            ]);

            if (SolverRun::query()->whereKey($runId)->whereNotNull('cancel_requested_at')->exists()) {
                throw new SolverRunCancelled("Se canceló la corrida {$runId}.");
            }
        };
    }

    /**
     * Las métricas del motor con el resumen de la salida.
     *
     * @return array<string, int|float|string>
     */
    private function metrics(SolverOutput $output): array
    {
        return [
            ...$output->metrics->toArray(),
            'result' => $output->status->value,
            'sessions' => count($output->sessions),
            'changed_sessions' => count(array_filter($output->sessions, fn (ProposedSession $session): bool => $session->changed)),
            'unscheduled' => count($output->unscheduled),
            'hard_violations' => count($output->hardViolations),
            'soft_score' => $output->softScore->total,
        ];
    }
}
