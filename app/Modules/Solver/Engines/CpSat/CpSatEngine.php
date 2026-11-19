<?php

namespace App\Modules\Solver\Engines\CpSat;

use App\Modules\Solver\Contract\ContractException;
use App\Modules\Solver\Contract\HintSession;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Contract\SolverOutput;
use App\Modules\Solver\Engines\PhpHeuristic\PhpHeuristicEngine;
use App\Modules\Solver\Engines\SolverEngine;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * El motor `cpsat` (tarea 7.9; docs/arquitectura/solver.md, «Cliente PHP (CpSatEngine)»): manda la entrada al servicio
 * Python (CpSatClient) y consulta la corrida cada `pollIntervalMs` hasta que termina. El resto de Nexus no sabe que hay
 * un servicio: es un motor más del registro.
 *
 * - En cada consulta de una corrida en cola o corriendo llama a `$progress` con el avance y el mejor puntaje del
 *   servicio. Si `$progress` lanza (la cancelación de RunSolver, SV-03), cancela la corrida en el servicio y deja salir
 *   la excepción.
 * - En `done` valida la salida contra el contrato; en `failed` lanza con el motivo del servicio.
 * - El límite es `time_limit_seconds` más DEADLINE_MARGIN_SECONDS desde que empieza solve(). Ninguna consulta ni
 *   reintento empieza si no terminaría antes del límite (CpSatClient::status()); cuando ya no cabe otra, o el servicio
 *   no contesta (consultas vencidas), cancela la corrida en el servicio y lanza «no respondió a tiempo».
 * - Si el servicio no está, lanza SolverServiceUnavailable (SV-08: la corrida queda fallida con el mensaje).
 *
 * - Antes de mandar la entrada corre la heurística en PHP (hintEngine) sobre la misma entrada, con el 15 % del límite
 *   (HINT_TIME_SHARE; la construcción siempre, la búsqueda local con ese tope), y manda su solución como `hint_sessions`
 *   (solo de los grupos movibles) para que CP-SAT arranque de ahí. Lo que gastó se descuenta del límite que se manda al
 *   servicio, sin bajar de MIN_SOLVE_SECONDS. Si la heurística falla, la corrida sigue sin pista y con el límite
 *   completo, y queda una advertencia en el log de la aplicación. `current_sessions` y la huella no se tocan.
 *
 * Cancelar en el servicio es de buena fe: si esa petición también falla, se ignora y sale el error original. No guarda
 * estado entre corridas (Octane).
 */
final readonly class CpSatEngine implements SolverEngine
{
    public const string KEY = 'cpsat';

    /** Su nombre en pantalla, también para las corridas de cuando el servicio estaba configurado y ya no lo está. */
    public const string LABEL = 'CP-SAT (servicio)';

    /**
     * Lo que se espera al servicio después del límite de la corrida: el tiempo de armar y mandar la salida. Con él, el
     * peor caso queda por debajo de RunSolver::TIMEOUT_MARGIN (60 s) y la corrida termina con este mensaje antes de
     * que la corte el worker. Con L = `time_limit_seconds` y los valores por omisión:
     *
     * - el envío (`timeout`, 30 s) termina antes de L + 30, porque L es de al menos 1 s;
     * - toda consulta, también un reintento, empieza solo si termina antes de L + 30 (`poll_timeout`, 5 s);
     * - después de la última que cabe vienen una pausa (`poll_interval_ms`, 1 s) y la cancelación (5 s).
     *
     * Peor caso: L + 30 + 1 + 5 = L + 36 s, más lo que tarda RunSolver en leer la entrada y guardar la salida; 24 s
     * por debajo de L + 60. Subir `poll_interval_ms`, `poll_timeout` o este margen gasta esa holgura.
     */
    public const int DEADLINE_MARGIN_SECONDS = 30;

    /** La parte del límite que se le da a la heurística para armar la pista (la construcción siempre; la búsqueda local, con ese tope). */
    public const float HINT_TIME_SHARE = 0.15;

    /**
     * Lo menos que se le manda a CP-SAT después de descontar a la heurística: con menos no alcanza a construir ni a
     * mejorar la pista. Nunca pasa del límite original de la corrida, pero la heurística más este piso sí pueden pasarlo
     * por unos segundos en corridas de menos de unos 12 s; eso cabe en DEADLINE_MARGIN_SECONDS y muy lejos de
     * RunSolver::STALE_AFTER, así que no vence la corrida.
     */
    public const int MIN_SOLVE_SECONDS = 10;

    public function __construct(
        private CpSatClient $client,
        private int $pollIntervalMs = 1000,
        private ?SolverEngine $hintEngine = null,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return self::LABEL;
    }

    public function supportedVersions(): array
    {
        return [SolverInput::VERSION];
    }

    public function checksHardRules(): bool
    {
        return true;
    }

    public function solve(SolverInput $input, ?Closure $progress = null): SolverOutput
    {
        $input = $this->withHint($input, $progress);
        $deadline = now()->addSeconds($input->timeLimitSeconds + self::DEADLINE_MARGIN_SECONDS);
        $runId = $this->client->submit($input->toArray());

        while (true) {
            try {
                $run = $this->client->status($runId, $deadline);
            } catch (SolverServiceTimedOut $exception) {
                $this->cancelQuietly($runId);

                throw CpSatRunFailed::timedOut($exception);
            } catch (SolverServiceUnavailable $exception) {
                // Un 5xx, la conexión perdida en todos los intentos o una respuesta sin estado: la corrida puede seguir
                // viva en el servicio ocupando un hilo, así que se cancela de buena fe antes de dejar salir el error.
                $this->cancelQuietly($runId);

                throw $exception;
            }

            if ($run['status'] === 'done') {
                return $this->output($input, $run['output'] ?? null);
            }

            match ($run['status']) {
                'queued', 'running' => $this->report($runId, $run, $progress),
                'failed' => throw CpSatRunFailed::failed($run['error'] ?? null),
                'cancelled' => throw CpSatRunFailed::cancelledByService(),
                default => $this->cancelAndThrow($runId, CpSatRunFailed::unknownStatus($run['status'])),
            };

            Sleep::for($this->pollIntervalMs)->milliseconds();
        }
    }

    /**
     * Corre la heurística con su parte del límite y devuelve la entrada para el servicio: con `hint_sessions` de los
     * grupos movibles y el límite menos lo que tardó la heurística (mínimo MIN_SOLVE_SECONDS, sin pasar del original).
     * Si la heurística falla, la entrada sale igual, sin pista y con el límite completo. Si `$progress` lanza (SV-03)
     * mientras corre, esa excepción sale: no es un fallo de la heurística.
     *
     * @param  (Closure(int, ?int): void)|null  $progress
     */
    private function withHint(SolverInput $input, ?Closure $progress): SolverInput
    {
        $started = now();
        $cancelled = null;
        $limit = $input->timeLimitSeconds;
        $hintInput = $this->copy($input, max(1, (int) ceil($limit * self::HINT_TIME_SHARE)), $input->hintSessions);

        try {
            $output = ($this->hintEngine ?? new PhpHeuristicEngine)->solve($hintInput, $progress === null ? null
                : function (int $percent, ?int $score) use ($progress, &$cancelled): void {
                    try {
                        $progress(0, $score);
                    } catch (Throwable $exception) {
                        $cancelled = $exception;

                        throw $exception;
                    }
                });
        } catch (Throwable $exception) {
            if ($cancelled !== null && $exception === $cancelled) {
                throw $exception;
            }

            Log::warning('CP-SAT: la heurística en PHP falló y la corrida sigue sin pista: '.$exception->getMessage(), ['run_id' => $input->runId]);

            return $input;
        }

        $movable = [];
        foreach ($input->groups as $group) {
            if ($group->movable) {
                $movable[$group->id] = true;
            }
        }

        $hints = [];
        foreach ($output->sessions as $session) {
            if (isset($movable[$session->groupId])) {
                $hints[] = new HintSession($session->groupId, $session->day, $session->startsAt, $session->endsAt, $session->kind, $session->roomId);
            }
        }

        if ($hints === []) {
            return $input;
        }

        $spent = (int) floor(max(0, $started->diffInMilliseconds(now(), true)) / 1000);
        $remaining = max(min($limit, self::MIN_SOLVE_SECONDS), $limit - $spent);

        return $this->copy($input, $remaining, $hints);
    }

    /**
     * @param  list<HintSession>  $hints
     */
    private function copy(SolverInput $input, int $timeLimitSeconds, array $hints): SolverInput
    {
        return new SolverInput($input->version, $input->runId, $input->termCode, $input->modalityCode, $input->campuses, $timeLimitSeconds,
            $input->scope, $input->travelMinutes, $input->weeklyHoursTolerance, $input->groups, $input->teachers, $input->rooms,
            $input->agreements, $input->subjectRoomDefaults, $input->hardRules, $input->softWeights, $input->options, $hints);
    }

    /**
     * @param  array{progress?: int, best_score?: int}  $run
     * @param  (Closure(int, ?int): void)|null  $progress
     */
    private function report(string $runId, array $run, ?Closure $progress): void
    {
        if ($progress === null) {
            return;
        }

        try {
            $progress(
                is_int($run['progress'] ?? null) ? max(0, min(100, $run['progress'])) : 0,
                is_int($run['best_score'] ?? null) ? $run['best_score'] : null,
            );
        } catch (Throwable $exception) {
            $this->cancelQuietly($runId);

            throw $exception;
        }
    }

    private function output(SolverInput $input, mixed $data): SolverOutput
    {
        try {
            $output = SolverOutput::fromArray(is_array($data) ? $data : []);
        } catch (ContractException $exception) {
            throw CpSatRunFailed::invalidOutput($exception);
        }

        return $output->runId === $input->runId ? $output : throw CpSatRunFailed::foreignOutput();
    }

    /**
     * Un estado que Nexus no conoce puede ser de una corrida que sigue viva en el servicio: se cancela antes del error.
     */
    private function cancelAndThrow(string $runId, Throwable $exception): never
    {
        $this->cancelQuietly($runId);

        throw $exception;
    }

    private function cancelQuietly(string $runId): void
    {
        try {
            $this->client->cancel($runId);
        } catch (Throwable) {
            // De buena fe: sin servicio no hay qué cancelar, y el error que importa es el de la corrida.
        }
    }
}
