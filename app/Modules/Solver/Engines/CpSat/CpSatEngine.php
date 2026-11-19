<?php

namespace App\Modules\Solver\Engines\CpSat;

use App\Modules\Solver\Contract\ContractException;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Contract\SolverOutput;
use App\Modules\Solver\Engines\SolverEngine;
use Closure;
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

    public function __construct(
        private CpSatClient $client,
        private int $pollIntervalMs = 1000,
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
        $deadline = now()->addSeconds($input->timeLimitSeconds + self::DEADLINE_MARGIN_SECONDS);
        $runId = $this->client->submit($input->toArray());

        while (true) {
            try {
                $run = $this->client->status($runId, $deadline);
            } catch (SolverServiceTimedOut $exception) {
                $this->cancelQuietly($runId);

                throw CpSatRunFailed::timedOut($exception);
            }

            if ($run['status'] === 'done') {
                return $this->output($input, $run['output'] ?? null);
            }

            match ($run['status']) {
                'queued', 'running' => $this->report($runId, $run, $progress),
                'failed' => throw CpSatRunFailed::failed($run['error'] ?? null),
                'cancelled' => throw CpSatRunFailed::cancelledByService(),
                default => throw CpSatRunFailed::unknownStatus($run['status']),
            };

            Sleep::for($this->pollIntervalMs)->milliseconds();
        }
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

    private function cancelQuietly(string $runId): void
    {
        try {
            $this->client->cancel($runId);
        } catch (Throwable) {
            // De buena fe: sin servicio no hay qué cancelar, y el error que importa es el de la corrida.
        }
    }
}
