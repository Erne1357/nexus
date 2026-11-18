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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Corre en la cola una corrida que lanzó LaunchSolverRun (tarea 6.8; docs/arquitectura/solver.md, «Ciclo de vida»).
 *
 * - Solo corre una corrida que sigue en cola, y la toma con una actualización condicional a `running`: un job repetido
 *   o el de una corrida cancelada no hace nada.
 * - Lee la entrada (RunPayloads), llama al motor de la corrida y le pasa un aviso de avance que, a lo más una vez por
 *   PROGRESS_INTERVAL_MS y en una sola consulta, guarda `progress` y `best_score` y lee `cancel_requested_at`. Si se
 *   pidió cancelar (SV-03), o si la corrida ya no está corriendo (se dio por perdida), lanza SolverRunCancelled para
 *   cortar al motor.
 * - Si el motor lanza cualquier excepción y se pidió cancelar, la corrida queda cancelada, sin salida y sin aviso: así
 *   un motor que envuelve el aviso en su propio `catch` no convierte la cancelación en una falla. Si el motor termina
 *   antes de notar la cancelación, la corrida queda terminada con su salida (supuesto por confirmar).
 * - Al terminar guarda la salida, validada contra el contrato, en la fila o en archivo; las métricas del motor con un
 *   resumen (`result`, `sessions`, `changed_sessions`, `unscheduled`, `hard_violations`, `soft_score`) para la
 *   pantalla y el aviso sin leer la salida, y la deja terminada aunque el motor diga `partial` o `infeasible`: la
 *   pantalla decide qué mostrar. Si el motor responde `error`, queda fallida con su salida y el primer renglón del log.
 *   Ese guardado revisa la fila bloqueada: si la corrida ya no está corriendo (LaunchSolverRun o failed() la dieron por
 *   perdida, o se canceló), no la pisa, borra el archivo de salida y no avisa.
 * - Si algo lanza, failed() deja fallida, con el mensaje y sin la traza, la corrida que siga en curso.
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

    /**
     * Una corrida en curso que no se movió en este tiempo se da por perdida (SV-01 y SV-03): el doble del límite más
     * largo, y ningún job pasa de ese límite más TIMEOUT_MARGIN.
     */
    public const int STALE_AFTER = 2 * LaunchSolverRun::MAX_TIME_LIMIT;

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
        } catch (Throwable $exception) {
            if ($exception instanceof SolverRunCancelled || $this->cancelRequested()) {
                $this->markCancelled();

                return;
            }

            throw $exception;
        }

        $output = SolverOutput::fromArray($output->toArray());
        $stored = RunPayloads::attributes($this->runId, 'output', $output->toArray());

        try {
            $finished = $this->finish($output, $stored);
        } catch (Throwable $exception) {
            RunPayloads::discard($stored);

            throw $exception;
        }

        if ($finished === null) {
            RunPayloads::discard($stored);

            return;
        }

        $finished->creator?->notify(new SolverRunFinished($finished));
    }

    /**
     * Deja fallida la corrida que corría (o que no llegó a correr) con el mensaje del error, y avisa a quien la lanzó.
     * Una que ya no está en curso no se toca.
     */
    public function failed(?Throwable $exception): void
    {
        $message = trim((string) $exception?->getMessage());

        $changed = SolverRun::query()
            ->whereKey($this->runId)
            ->whereIn('status', [SolverRunStatus::Queued, SolverRunStatus::Running])
            ->update([
                'status' => SolverRunStatus::Failed,
                'finished_at' => now(),
                'error' => Str::limit($message === '' ? 'La corrida no terminó.' : $message, 1000),
            ]);

        if ($changed === 1) {
            $run = SolverRun::query()->findOrFail($this->runId);
            $run->creator?->notify(new SolverRunFinished($run));
        }
    }

    /**
     * Guarda la salida si la corrida sigue corriendo, con la fila bloqueada; si no, no toca nada y devuelve null.
     *
     * @param  array<string, mixed>  $stored  las columnas de la salida (RunPayloads::attributes)
     */
    private function finish(SolverOutput $output, array $stored): ?SolverRun
    {
        $failed = $output->status === RunStatus::Error;

        return DB::transaction(function () use ($output, $stored, $failed): ?SolverRun {
            $run = SolverRun::query()->lockForUpdate()->find($this->runId);

            if ($run?->status !== SolverRunStatus::Running) {
                return null;
            }

            $run->forceFill([
                ...$stored,
                'metrics' => $this->metrics($output),
                'status' => $failed ? SolverRunStatus::Failed : SolverRunStatus::Done,
                'error' => $failed ? Str::limit($output->log[0] ?? 'El motor terminó con error.', 1000) : null,
                'progress' => 100,
                'best_score' => $output->softScore->total,
                'finished_at' => now(),
            ])->save();

            return $run;
        });
    }

    private function cancelRequested(): bool
    {
        return SolverRun::query()->whereKey($this->runId)->whereNotNull('cancel_requested_at')->exists();
    }

    /**
     * SV-03: la corrida que sigue corriendo queda cancelada; una que ya no corre no se toca.
     */
    private function markCancelled(): void
    {
        SolverRun::query()
            ->whereKey($this->runId)
            ->where('status', SolverRunStatus::Running)
            ->update(['status' => SolverRunStatus::Cancelled, 'finished_at' => now()]);
    }

    /**
     * El aviso de avance para el motor: a lo más una vez por PROGRESS_INTERVAL_MS, guarda el avance (de 0 a 100) y el
     * mejor puntaje, si lo trae, y lee si se pidió cancelar, todo en una consulta. Corta al motor si se pidió cancelar o
     * si la corrida ya no está corriendo.
     *
     * @return Closure(int, ?int): void
     */
    private function progress(string $runId): Closure
    {
        $lastCheck = null;
        $table = (new SolverRun)->getTable();

        return function (int $percent, ?int $bestScore) use ($runId, $table, &$lastCheck): void {
            $now = now();

            if ($lastCheck !== null && $now->getTimestampMs() - $lastCheck < self::PROGRESS_INTERVAL_MS) {
                return;
            }

            $lastCheck = $now->getTimestampMs();

            $row = DB::selectOne(
                "update {$table} set progress = ?, best_score = coalesce(?, best_score), updated_at = ? where id = ? and status = ? returning cancel_requested_at",
                [max(0, min(100, $percent)), $bestScore, $now, $runId, SolverRunStatus::Running->value],
            );

            if ($row === null || $row->cancel_requested_at !== null) {
                throw new SolverRunCancelled("Se detuvo la corrida {$runId}.");
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
