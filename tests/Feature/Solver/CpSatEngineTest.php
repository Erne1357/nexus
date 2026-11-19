<?php

use App\Modules\Solver\Actions\CancelSolverRun;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Contract\SolverOutput;
use App\Modules\Solver\Engines\CpSat\CpSatClient;
use App\Modules\Solver\Engines\CpSat\CpSatEngine;
use App\Modules\Solver\Engines\CpSat\CpSatRunFailed;
use App\Modules\Solver\Engines\CpSat\SolverInputRejected;
use App\Modules\Solver\Engines\CpSat\SolverRunNotFound;
use App\Modules\Solver\Engines\CpSat\SolverServiceUnavailable;
use App\Modules\Solver\Engines\EngineRegistry;
use App\Modules\Solver\Engines\FixedEngine;
use App\Modules\Solver\Engines\PhpHeuristic\PhpHeuristicEngine;
use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Jobs\RunSolver;
use App\Modules\Solver\Jobs\SolverRunCancelled;
use App\Modules\Solver\Models\SolverRun;
use App\Modules\Solver\Notifications\SolverRunFinished;
use App\Modules\Solver\SolverServiceProvider;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;

/*
| CpSatEngine (tarea 7.9; docs/arquitectura/solver.md, «Cliente PHP (CpSatEngine)»): el motor `cpsat` manda la entrada
| al servicio Python, consulta la corrida cada segundo, reporta su avance y devuelve la salida validada contra el
| contrato. El servicio se simula con Http::fake() y respuestas grabadas de su API real (tests/Fixtures/solver/cpsat/,
| sacadas con el TestClient de FastAPI sobre la entrada `tiny`: `done` trae la salida de referencia de tiny; `queued`
| se armó con la misma función que responde GET /runs/{id}, y `rejected-day-end` a mano, con la forma de un 422 real).
| Sleep va simulado y mueve el reloj: el tiempo de espera se prueba sin esperar.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake(syncWithCarbon: true);
});

/**
 * Una respuesta grabada del servicio: su código HTTP y su cuerpo.
 *
 * @return array{status: int, body: mixed}
 */
function cpsatRecording(string $name): array
{
    return json_decode(file_get_contents(__DIR__.'/../../Fixtures/solver/cpsat/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @param  string|array{status: int, body: mixed}  $recording  el nombre de una grabación, la grabación misma,
 *                                                             `connection-lost` para una conexión rechazada o
 *                                                             `timed-out` para una consulta que se vence (el reloj
 *                                                             avanza los 5 s de `poll_timeout`)
 */
function cpsatResponse(string|array $recording, Request $request): PromiseInterface
{
    if ($recording === 'connection-lost') {
        return Factory::failedConnection('cURL error 7: Failed to connect to solver port 8000: Connection refused')($request);
    }

    if ($recording === 'timed-out') {
        Carbon::setTestNow(now()->addSeconds(5));

        return Factory::failedConnection('cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received')($request);
    }

    $recording = is_string($recording) ? cpsatRecording($recording) : $recording;

    return Http::response($recording['body'], $recording['status']);
}

/**
 * Simula el servicio: POST /solve responde `$submit`; cada GET /runs/{id} responde la siguiente de `$polls` (la última
 * se repite), y antes de responder corre `$onPoll` con el número de consulta; DELETE responde `cancelled`.
 *
 * @param  list<string|array{status: int, body: mixed}>  $polls
 * @param  string|array{status: int, body: mixed}  $submit
 * @param  (Closure(int): void)|null  $onPoll
 */
function cpsatFakeService(array $polls, string|array $submit = 'submitted', ?Closure $onPoll = null): void
{
    $poll = 0;

    Http::fake(function (Request $request) use ($polls, $submit, $onPoll, &$poll): PromiseInterface {
        if ($request->method() === 'POST') {
            return cpsatResponse($submit, $request);
        }

        if ($request->method() === 'DELETE') {
            return cpsatResponse('cancelled', $request);
        }

        $poll++;
        if ($onPoll !== null) {
            $onPoll($poll);
        }

        return cpsatResponse($polls[min($poll, count($polls)) - 1], $request);
    });
}

function cpsatEngine(): CpSatEngine
{
    return new CpSatEngine(new CpSatClient('http://solver.test', 'secreto-de-prueba'));
}

function cpsatTinyInput(): SolverInput
{
    return SolverInput::fromArray(json_decode(file_get_contents(__DIR__.'/../../Fixtures/solver/v1/tiny/input.json'), true, flags: JSON_THROW_ON_ERROR));
}

/**
 * Las peticiones que recibió el servicio simulado, como «MÉTODO ruta».
 *
 * @return list<string>
 */
function cpsatSentRequests(): array
{
    return Http::recorded()->map(fn (array $pair): string => $pair[0]->method().' '.parse_url($pair[0]->url(), PHP_URL_PATH))->values()->all();
}

/**
 * Una corrida de departamento en cola con el motor `cpsat` y la entrada `tiny`, y el registro con ese motor apuntando
 * al servicio simulado.
 */
function cpsatQueuedRun(): SolverRun
{
    $run = SolverRun::factory()->create(['engine' => CpSatEngine::KEY]);
    $input = json_decode(file_get_contents(__DIR__.'/../../Fixtures/solver/v1/tiny/input.json'), true, flags: JSON_THROW_ON_ERROR);
    $input['run_id'] = $run->id;
    $run->update(['input' => $input]);

    app()->instance(EngineRegistry::class, new EngineRegistry([cpsatEngine(), new FixedEngine]));

    return $run;
}

it('envía la entrada, consulta cada segundo con su avance y devuelve la salida del servicio', function () {
    cpsatFakeService(['queued', 'running', 'done']);
    $input = cpsatTinyInput();
    $reported = [];

    $output = cpsatEngine()->solve($input, function (int $percent, ?int $bestScore) use (&$reported): void {
        $reported[] = [$percent, $bestScore];
    });

    $runId = cpsatRecording('submitted')['body']['run_id'];

    expect($output)->toBeInstanceOf(SolverOutput::class)
        ->and($output->toArray())->toBe(SolverOutput::fromArray(cpsatRecording('done')['body']['output'])->toArray())
        ->and($output->runId)->toBe($input->runId)
        ->and($reported)->toBe([[0, null], [40, 120]])
        ->and(cpsatSentRequests())->toBe(['POST /solve', "GET /runs/{$runId}", "GET /runs/{$runId}", "GET /runs/{$runId}"]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->data() === $input->toArray());
    Sleep::assertSequence([Sleep::for(1000)->milliseconds(), Sleep::for(1000)->milliseconds()]);
});

it('manda el token compartido de la configuración en cada petición', function () {
    config(['services.solver.url' => 'http://motor.interno:8000', 'services.solver.token' => 'token-de-config']);
    cpsatFakeService(['running', 'done']);

    app(CpSatEngine::class)->solve(cpsatTinyInput());

    expect(Http::recorded())->toHaveCount(3)
        ->and(Http::recorded()->every(fn (array $pair): bool => $pair[0]->hasHeader('Authorization', 'Bearer token-de-config')
            && str_starts_with($pair[0]->url(), 'http://motor.interno:8000/')))->toBeTrue();
});

it('una corrida que falla en el servicio lanza su motivo', function () {
    cpsatFakeService(['running', 'failed']);

    expect(fn () => cpsatEngine()->solve(cpsatTinyInput()))
        ->toThrow(CpSatRunFailed::class, 'El servicio del motor no pudo resolver la corrida: RuntimeError: el modelo no cupo en memoria');

    expect(cpsatSentRequests())->not->toContain('DELETE /runs/'.cpsatRecording('submitted')['body']['run_id']);
});

it('si el aviso de avance corta la corrida (cancelación), la cancela en el servicio y deja salir la excepción', function () {
    cpsatFakeService(['running']);
    $calls = 0;

    expect(fn () => cpsatEngine()->solve(cpsatTinyInput(), function () use (&$calls): void {
        if (++$calls === 2) {
            throw new SolverRunCancelled('Se detuvo la corrida.');
        }
    }))->toThrow(SolverRunCancelled::class, 'Se detuvo la corrida.');

    $runId = cpsatRecording('submitted')['body']['run_id'];

    expect(cpsatSentRequests())->toBe(['POST /solve', "GET /runs/{$runId}", "GET /runs/{$runId}", "DELETE /runs/{$runId}"]);
});

it('cancela la corrida en el servicio y lanza un tiempo agotado cuando otra consulta ya no cabe en el límite más el margen', function () {
    cpsatFakeService(['running']);
    $input = cpsatTinyInput();
    $started = now();

    expect(fn () => cpsatEngine()->solve($input))->toThrow(CpSatRunFailed::class, 'El servicio del motor no respondió a tiempo.');

    $requests = cpsatSentRequests();
    $deadline = $input->timeLimitSeconds + CpSatEngine::DEADLINE_MARGIN_SECONDS;

    expect(end($requests))->toBe('DELETE /runs/'.cpsatRecording('submitted')['body']['run_id'])
        ->and($started->diffInSeconds(now()))->toBeGreaterThanOrEqual($deadline - 5)->toBeLessThanOrEqual($deadline);
});

it('con el servicio colgado, cancela y lanza «no respondió a tiempo» antes de que el worker corte la corrida', function (int $hangsAfter, int $hungPolls) {
    $input = cpsatTinyInput();
    $started = now();
    $hung = 0;

    Http::fake(function (Request $request) use ($started, $hangsAfter, &$hung): PromiseInterface {
        if ($request->method() !== 'GET') {
            return cpsatResponse($request->method() === 'POST' ? 'submitted' : 'cancelled', $request);
        }

        if ($started->diffInSeconds(now()) < $hangsAfter) {
            return cpsatResponse('running', $request);
        }

        $hung++;

        return cpsatResponse('timed-out', $request);
    });

    expect(fn () => cpsatEngine()->solve($input))->toThrow(CpSatRunFailed::class, 'El servicio del motor no respondió a tiempo.');

    $requests = cpsatSentRequests();

    expect(end($requests))->toBe('DELETE /runs/'.cpsatRecording('submitted')['body']['run_id'])
        ->and($hung)->toBe($hungPolls)
        ->and($started->diffInSeconds(now()))->toBeLessThanOrEqual($input->timeLimitSeconds + CpSatEngine::DEADLINE_MARGIN_SECONDS)
        ->toBeLessThan($input->timeLimitSeconds + RunSolver::TIMEOUT_MARGIN);
})->with([
    'desde la primera consulta: se vencen los tres intentos' => [0, 3],
    'cerca del límite: el reintento ya no cabe' => [33, 1],
]);

it('sin servicio (conexión rechazada) lanza que no está disponible y no reintenta el envío', function () {
    Http::fake(['*' => Http::failedConnection('cURL error 7: Failed to connect to solver port 8000: Connection refused')]);

    expect(fn () => cpsatEngine()->solve(cpsatTinyInput()))
        ->toThrow(SolverServiceUnavailable::class, 'El servicio del motor no está disponible.');

    expect(cpsatSentRequests())->toBe(['POST /solve']);
});

it('reintenta una consulta que perdió la conexión y sigue con la corrida', function () {
    cpsatFakeService(['connection-lost', 'done']);

    $output = cpsatEngine()->solve(cpsatTinyInput());

    $runId = cpsatRecording('submitted')['body']['run_id'];

    expect($output->runId)->toBe(cpsatTinyInput()->runId)
        ->and(cpsatSentRequests())->toBe(['POST /solve', "GET /runs/{$runId}", "GET /runs/{$runId}"]);
});

it('una entrada rechazada (422) lanza el detalle legible, con la ruta de cada campo', function (string $recording, string $message) {
    cpsatFakeService(['running'], submit: $recording);

    try {
        cpsatEngine()->solve(cpsatTinyInput());
        $this->fail('Debió rechazar la entrada.');
    } catch (SolverInputRejected $rejected) {
        expect($rejected->getMessage())->toBe($message)
            ->and($rejected->problems)->toBe(cpsatRecording($recording)['body']['detail']);
    }

    expect(cpsatSentRequests())->toBe(['POST /solve']);
})->with([
    'dos campos' => ['rejected', 'El servicio del motor rechazó la entrada: groups[1].weekly_hours: debe ser un entero de 1 en adelante; teachers[0].remaining_hours: debe ser un entero de 0 en adelante.'],
    'bloque que no divide la jornada' => ['rejected-day-end', 'El servicio del motor rechazó la entrada: campuses[0].day_end: la jornada de 07:00 a 21:30 no se divide en bloques de 60 minutos.'],
]);

it('una salida que no cumple el contrato en done lanza un error claro', function (Closure $break, string $message) {
    $done = cpsatRecording('done');
    $done['body']['output'] = $break($done['body']['output']);
    cpsatFakeService([$done]);

    expect(fn () => cpsatEngine()->solve(cpsatTinyInput()))->toThrow(CpSatRunFailed::class, $message);
})->with([
    'un día fuera de la semana' => [function (array $output): array {
        $output['sessions'][0]['day'] = 9;

        return $output;
    }, 'El servicio del motor devolvió una salida que no cumple el contrato: sessions[0].day: debe ser un entero entre 1 y 6'],
    'sin salida' => [fn (): null => null, 'El servicio del motor devolvió una salida que no cumple el contrato: version: es obligatorio'],
    'la salida de otra corrida' => [function (array $output): array {
        $output['run_id'] = '0190a000-0000-7000-8000-0000000000ff';

        return $output;
    }, 'El servicio del motor devolvió la salida de otra corrida.'],
]);

it('un servicio lleno, con el token rechazado o con un error interno lanza que no está disponible, con el motivo', function (array $recording, string $message) {
    cpsatFakeService(['running'], submit: $recording);

    expect(fn () => cpsatEngine()->solve(cpsatTinyInput()))->toThrow(SolverServiceUnavailable::class, $message);
})->with([
    'lleno (429)' => [cpsatRecording('busy'), 'El servicio del motor está ocupado con otras corridas: vuelve a lanzarla en unos minutos.'],
    'token rechazado (401)' => [cpsatRecording('unauthorized'), 'El servicio del motor no está disponible: rechazó el token de Nexus (SOLVER_TOKEN).'],
    'error interno (500)' => [['status' => 500, 'body' => 'Internal Server Error'], 'El servicio del motor no está disponible.'],
]);

it('si una consulta de avance falla (5xx, sin conexión o respuesta rara), cancela la corrida en el servicio antes de lanzar el error', function (array|string $failure, string $exception) {
    cpsatFakeService(['running', $failure]);

    expect(fn () => cpsatEngine()->solve(cpsatTinyInput()))->toThrow($exception);

    $runId = cpsatRecording('submitted')['body']['run_id'];
    $requests = cpsatSentRequests();

    expect(end($requests))->toBe("DELETE /runs/{$runId}");
})->with([
    'error interno (500)' => [['status' => 500, 'body' => 'Internal Server Error'], SolverServiceUnavailable::class],
    'puerta de enlace (502)' => [['status' => 502, 'body' => 'Bad Gateway'], SolverServiceUnavailable::class],
    'respuesta sin estado' => [['status' => 200, 'body' => ['progress' => 10]], SolverServiceUnavailable::class],
    'estado desconocido' => [['status' => 200, 'body' => ['run_id' => 'x', 'status' => 'paused']], CpSatRunFailed::class],
]);

it('una corrida que ya terminó en el servicio (fallida o cancelada allá) no se vuelve a cancelar', function (string $recording) {
    cpsatFakeService(['running', $recording]);

    expect(fn () => cpsatEngine()->solve(cpsatTinyInput()))->toThrow(CpSatRunFailed::class);

    expect(cpsatSentRequests())->not->toContain('DELETE /runs/'.cpsatRecording('submitted')['body']['run_id']);
})->with(['failed', 'cancelled']);

it('una corrida que el servicio ya no tiene (404, se reinició) lanza que hay que volver a lanzarla', function () {
    cpsatFakeService(['running', 'not-found']);

    expect(fn () => cpsatEngine()->solve(cpsatTinyInput()))
        ->toThrow(SolverRunNotFound::class, 'El servicio del motor ya no tiene esta corrida (pudo reiniciarse): vuelve a lanzarla.');
});

it('la salud dice si el servicio responde y entiende la versión del contrato', function () {
    $client = new CpSatClient('http://solver.test', 'secreto-de-prueba');
    $health = cpsatRecording('health');

    Http::fake(['solver.test/health' => Http::sequence()
        ->push($health['body'])
        ->push([...$health['body'], 'contract_versions' => [2]])
        ->pushFailedConnection()]);

    expect($client->health())->toBeTrue()
        ->and($client->health())->toBeFalse()
        ->and($client->health())->toBeFalse();
});

it('cpsat se registra solo con URL y token del servicio, antes del de prueba y de la heurística, para no quedar como predeterminado', function (?string $url, ?string $token, array $engines) {
    config(['services.solver.url' => $url, 'services.solver.token' => $token]);

    expect(SolverServiceProvider::engineClasses())->toBe($engines);
})->with([
    'con URL y token' => ['http://solver:8000', 'secreto', [CpSatEngine::class, FixedEngine::class, PhpHeuristicEngine::class]],
    'sin token' => ['http://solver:8000', '', [FixedEngine::class, PhpHeuristicEngine::class]],
    'sin URL' => [null, 'secreto', [FixedEngine::class, PhpHeuristicEngine::class]],
]);

it('en las pruebas, sin servicio configurado, el registro no trae cpsat', function () {
    expect(array_map(fn ($engine): string => $engine->key(), app(EngineRegistry::class)->all()))->not->toContain(CpSatEngine::KEY);
});

it('SV-08: sin servicio, la corrida queda fallida con «El servicio del motor no está disponible.» y avisa a quien la lanzó', function () {
    Notification::fake();
    Http::fake(['*' => Http::failedConnection()]);
    $run = cpsatQueuedRun();

    expect(fn () => RunSolver::dispatchSync($run->id))->toThrow(SolverServiceUnavailable::class)
        ->and($run->fresh())
        ->status->toBe(SolverRunStatus::Failed)
        ->error->toBe('El servicio del motor no está disponible.')
        ->output->toBeNull();

    Notification::assertSentTo($run->creator, SolverRunFinished::class);
});

it('SV-03: cancelar una corrida de cpsat que corre la cancela también en el servicio', function () {
    Notification::fake();
    $run = cpsatQueuedRun();
    cpsatFakeService(['running'], onPoll: function (int $poll) use ($run): void {
        if ($poll === 2) {
            app(CancelSolverRun::class)->handle(SolverRun::query()->findOrFail($run->id), $run->creator);
        }
    });

    RunSolver::dispatchSync($run->id);

    expect($run->fresh())->status->toBe(SolverRunStatus::Cancelled)->output->toBeNull()
        ->and(cpsatSentRequests())->toContain('DELETE /runs/'.cpsatRecording('submitted')['body']['run_id']);

    Notification::assertNothingSent();
});
