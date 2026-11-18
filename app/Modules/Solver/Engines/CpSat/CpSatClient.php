<?php

namespace App\Modules\Solver\Engines\CpSat;

use App\Modules\Solver\Contract\SolverInput;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

/**
 * El cliente HTTP del servicio del motor en Python (tarea 7.9; services/solver/README.md, «API»). Manda el token
 * compartido como `Authorization: Bearer` y traduce cada respuesta que no es la esperada a un error tipado:
 * SolverServiceUnavailable (no se pudo conectar, 5xx, 429, 401 u otra cosa), SolverInputRejected (422, con el detalle)
 * y SolverRunNotFound (404).
 *
 * Solo las consultas (GET) se reintentan, y solo si se perdió la conexión: un POST repetido encolaría la corrida dos
 * veces. No guarda estado entre llamadas (Octane): cada petición se arma de nuevo, también para que Http::fake() la vea.
 */
final readonly class CpSatClient
{
    /** Intentos de una consulta que pierde la conexión, y la pausa entre ellos. */
    private const int GET_ATTEMPTS = 3;

    private const int RETRY_DELAY_MS = 200;

    public function __construct(
        private string $baseUrl,
        #[SensitiveParameter] private string $token,
        private float $connectTimeoutSeconds = 3,
        private float $timeoutSeconds = 30,
    ) {}

    /**
     * El cliente con la URL, el token y los tiempos de `services.solver`.
     */
    public static function fromConfig(): self
    {
        return new self(
            (string) config('services.solver.url'),
            (string) config('services.solver.token'),
            (float) config('services.solver.connect_timeout', 3),
            (float) config('services.solver.timeout', 30),
        );
    }

    /**
     * Si hay servicio que llamar: con URL y token en `services.solver` (SOLVER_URL y SOLVER_TOKEN).
     */
    public static function configured(): bool
    {
        return trim((string) config('services.solver.url')) !== '' && trim((string) config('services.solver.token')) !== '';
    }

    /**
     * Encola la corrida en el servicio (`POST /solve`) y devuelve el identificador que le dio el servicio, distinto del
     * `run_id` de la entrada.
     *
     * @param  array<string, mixed>  $input  la entrada del contrato (SolverInput::toArray)
     *
     * @throws SolverServiceUnavailable
     * @throws SolverInputRejected
     */
    public function submit(array $input): string
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->post('solve', $input));

        if ($response->status() === 422) {
            throw SolverInputRejected::fromDetail($response->json('detail'));
        }

        $this->assertSuccessful($response);
        $runId = $response->json('run_id');

        return is_string($runId) && $runId !== '' ? $runId : throw SolverServiceUnavailable::unexpected($response->status());
    }

    /**
     * El estado de una corrida (`GET /runs/{id}`): `status` (queued, running, done, failed o cancelled), `progress` de 0
     * a 100 y, si los hay, `best_score`, `output` (en done) y `error` (en failed).
     *
     * @return array{run_id: string, status: string, progress?: int, best_score?: int, output?: array<string, mixed>, error?: string}
     *
     * @throws SolverServiceUnavailable
     * @throws SolverRunNotFound
     */
    public function status(string $runId): array
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->get('runs/'.rawurlencode($runId)), retry: true);

        if ($response->status() === 404) {
            throw new SolverRunNotFound($runId);
        }

        $this->assertSuccessful($response);
        $run = $response->json();

        return is_array($run) && is_string($run['status'] ?? null) ? $run : throw SolverServiceUnavailable::unexpected($response->status());
    }

    /**
     * Cancela una corrida (`DELETE /runs/{id}`). Una que ya terminó conserva su estado en el servicio.
     *
     * @throws SolverServiceUnavailable
     * @throws SolverRunNotFound
     */
    public function cancel(string $runId): void
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->delete('runs/'.rawurlencode($runId)));

        if ($response->status() === 404) {
            throw new SolverRunNotFound($runId);
        }

        $this->assertSuccessful($response);
    }

    /**
     * Si el servicio responde su salud (`GET /health`, sin reintentos) y entiende la versión del contrato que manda
     * Nexus.
     */
    public function health(): bool
    {
        try {
            $response = $this->send(fn (PendingRequest $request): Response => $request->get('health'));
        } catch (SolverServiceUnavailable) {
            return false;
        }

        return $response->successful()
            && $response->json('status') === 'ok'
            && in_array(SolverInput::VERSION, (array) $response->json('contract_versions'), true);
    }

    /**
     * @param  Closure(PendingRequest): Response  $call
     *
     * @throws SolverServiceUnavailable si no se pudo conectar (también tras los reintentos de una consulta)
     */
    private function send(Closure $call, bool $retry = false): Response
    {
        $request = Http::baseUrl($this->baseUrl)
            ->withToken($this->token)
            ->acceptJson()
            ->connectTimeout($this->connectTimeoutSeconds)
            ->timeout($this->timeoutSeconds);

        if ($retry) {
            $request->retry(self::GET_ATTEMPTS, self::RETRY_DELAY_MS, fn (Throwable $exception): bool => $exception instanceof ConnectionException, throw: false);
        }

        try {
            return $call($request);
        } catch (ConnectionException $exception) {
            throw SolverServiceUnavailable::unreachable($exception);
        }
    }

    /**
     * @throws SolverServiceUnavailable
     */
    private function assertSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw match (true) {
            $response->status() === 429 => SolverServiceUnavailable::busy(),
            $response->status() === 401 => SolverServiceUnavailable::tokenRejected(),
            $response->serverError() => SolverServiceUnavailable::unreachable(),
            default => SolverServiceUnavailable::unexpected($response->status()),
        };
    }
}
