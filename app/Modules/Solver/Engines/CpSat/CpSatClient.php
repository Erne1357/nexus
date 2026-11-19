<?php

namespace App\Modules\Solver\Engines\CpSat;

use App\Modules\Solver\Contract\SolverInput;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use SensitiveParameter;

/**
 * El cliente HTTP del servicio del motor en Python (tarea 7.9; services/solver/README.md, «API»). Manda el token
 * compartido como `Authorization: Bearer` y traduce cada respuesta que no es la esperada a un error tipado:
 * SolverServiceUnavailable (no se pudo conectar, 5xx, 429, 401 u otra cosa), SolverInputRejected (422, con el detalle),
 * SolverRunNotFound (404) y SolverServiceTimedOut (consultas vencidas o sin tiempo para otra).
 *
 * Tiempos: `connect_timeout` para conectar; `timeout` (30 s) para el envío, que lleva la entrada grande; `poll_timeout`
 * (5 s) para cada consulta, cancelación o salud.
 *
 * Solo las consultas (GET) se reintentan, y solo si se perdió la conexión o se vencieron: un POST repetido encolaría la
 * corrida dos veces. Por lo mismo, un envío que se vence después de que el servicio lo aceptó deja la corrida huérfana
 * en el servicio: Nexus no recibió su identificador y el servicio no conoce el `run_id` de Nexus para que alguien la
 * cancele, así que ocupa un lugar hasta que termina sola (en su `time_limit_seconds`) y su salida nadie la recoge.
 *
 * No guarda estado entre llamadas (Octane): cada petición se arma de nuevo, también para que Http::fake() la vea.
 */
final readonly class CpSatClient
{
    /** Intentos de una consulta que pierde la conexión o se vence, y la pausa entre ellos. */
    private const int GET_ATTEMPTS = 3;

    private const int RETRY_DELAY_MS = 200;

    public function __construct(
        private string $baseUrl,
        #[SensitiveParameter] private string $token,
        private float $connectTimeoutSeconds = 3,
        private float $timeoutSeconds = 30,
        private float $pollTimeoutSeconds = 5,
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
            (float) config('services.solver.poll_timeout', 5),
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
     * `run_id` de la entrada. No se reintenta (ver el docblock de la clase: un envío vencido puede quedar huérfano).
     *
     * @param  array<string, mixed>  $input  la entrada del contrato (SolverInput::toArray)
     *
     * @throws SolverServiceUnavailable
     * @throws SolverInputRejected
     */
    public function submit(array $input): string
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->post('solve', $input), $this->timeoutSeconds);

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
     * Con `$deadline`, ninguna consulta empieza si no terminaría antes (su `poll_timeout`), tampoco un reintento.
     *
     * @return array{run_id: string, status: string, progress?: int, best_score?: int, output?: array<string, mixed>, error?: string}
     *
     * @throws SolverServiceUnavailable si no se pudo conectar después de los reintentos, o respondió con error
     * @throws SolverServiceTimedOut si las consultas se vencieron todas o la siguiente ya no cabe antes de `$deadline`
     * @throws SolverRunNotFound
     */
    public function status(string $runId, ?CarbonInterface $deadline = null): array
    {
        $response = $this->poll('runs/'.rawurlencode($runId), $deadline);

        if ($response->status() === 404) {
            throw new SolverRunNotFound($runId);
        }

        $this->assertSuccessful($response);
        $run = $response->json();

        return is_array($run) && is_string($run['status'] ?? null) ? $run : throw SolverServiceUnavailable::unexpected($response->status());
    }

    /**
     * Cancela una corrida (`DELETE /runs/{id}`), sin reintentos. Una que ya terminó conserva su estado en el servicio.
     *
     * @throws SolverServiceUnavailable
     * @throws SolverRunNotFound
     */
    public function cancel(string $runId): void
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->delete('runs/'.rawurlencode($runId)), $this->pollTimeoutSeconds);

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
            $response = $this->send(fn (PendingRequest $request): Response => $request->get('health'), $this->pollTimeoutSeconds);
        } catch (SolverServiceUnavailable) {
            return false;
        }

        return $response->successful()
            && $response->json('status') === 'ok'
            && in_array(SolverInput::VERSION, (array) $response->json('contract_versions'), true);
    }

    /**
     * Una consulta con hasta GET_ATTEMPTS intentos si se pierde la conexión o se vence. Antes de cada intento revisa que
     * termine antes de `$deadline`.
     *
     * @throws SolverServiceUnavailable
     * @throws SolverServiceTimedOut
     */
    private function poll(string $path, ?CarbonInterface $deadline): Response
    {
        for ($attempt = 1; ; $attempt++) {
            if ($deadline !== null && now()->addMilliseconds((int) ($this->pollTimeoutSeconds * 1000))->greaterThan($deadline)) {
                throw new SolverServiceTimedOut;
            }

            try {
                return $this->request($this->pollTimeoutSeconds)->get($path);
            } catch (ConnectionException $exception) {
                if ($attempt >= self::GET_ATTEMPTS) {
                    throw self::timedOut($exception) ? new SolverServiceTimedOut($exception) : SolverServiceUnavailable::unreachable($exception);
                }

                Sleep::for(self::RETRY_DELAY_MS)->milliseconds();
            }
        }
    }

    /**
     * @param  Closure(PendingRequest): Response  $call
     *
     * @throws SolverServiceUnavailable si no se pudo conectar o la petición se venció
     */
    private function send(Closure $call, float $timeoutSeconds): Response
    {
        try {
            return $call($this->request($timeoutSeconds));
        } catch (ConnectionException $exception) {
            throw SolverServiceUnavailable::unreachable($exception);
        }
    }

    private function request(float $timeoutSeconds): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->token)
            ->acceptJson()
            ->connectTimeout(min($this->connectTimeoutSeconds, $timeoutSeconds))
            ->timeout($timeoutSeconds);
    }

    /**
     * Si la conexión se perdió porque la petición se venció (cURL 28) y no porque el servicio la rechazó: el servicio
     * está ahí pero no contesta.
     */
    private static function timedOut(ConnectionException $exception): bool
    {
        return str_starts_with($exception->getMessage(), 'cURL error 28:');
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
