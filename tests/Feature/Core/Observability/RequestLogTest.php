<?php

use App\Modules\Core\Http\Middleware\TagModule;
use App\Modules\Core\Models\User;
use App\Modules\Core\Observability\CanonicalLog;
use App\Modules\Core\Observability\OperationMetrics;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Octane\CurrentApplication;

/*
| Fase 2b: toda petición lleva un X-Request-Id y deja una sola línea `request.done` con una lista blanca de claves,
| sin datos personales. Las sondas (/health, /up) no escriben línea ni abren sesión.
*/

function captureLog(): ArrayObject
{
    $calls = new ArrayObject;
    Log::spy()->shouldReceive('log')->andReturnUsing(function (string $level, string $message, array $context = []) use ($calls): void {
        $calls[] = compact('level', 'message', 'context');
    });

    return $calls;
}

function doneLines(ArrayObject $calls): array
{
    return array_values(array_filter($calls->getArrayCopy(), fn (array $c): bool => $c['message'] === 'request.done'));
}

it('respeta el X-Request-Id que pone Caddy', function () {
    $calls = captureLog();
    $id = '0193f0c2-aaaa-7000-8000-000000000002';

    $this->get('/login', ['X-Request-Id' => $id])->assertHeader('X-Request-Id', $id);

    expect(doneLines($calls)[0]['context']['request_id'])->toBe($id);
});

it('genera uno nuevo si no viene o no es válido', function (string $sent) {
    $calls = captureLog();
    $headers = $sent === '' ? [] : ['X-Request-Id' => $sent];

    $response = $this->get('/login', $headers);
    $id = $response->headers->get('X-Request-Id');

    expect($id)->toMatch('/^[A-Za-z0-9-]{8,64}$/')->not->toBe($sent);
    if ($sent !== '') {
        expect(json_encode(doneLines($calls)))->not->toContain($sent);
    }
    expect(doneLines($calls)[0]['context']['request_id'])->toBe($id);
})->with([
    'salto de línea' => ["a\nb"],
    'salto de línea al final' => ["0193f0c2-aaaa\n"],
    '1 KB' => [str_repeat('x', 1024)],
    'sin cabecera' => [''],
]);

it('escribe una línea con solo las claves de la lista blanca', function () {
    $calls = captureLog();

    $this->get('/login?buscar=Juana');

    $lines = doneLines($calls);
    expect($lines)->toHaveCount(1)
        ->and(array_keys($lines[0]['context']))->toBe(CanonicalLog::REQUEST_KEYS)
        ->and($lines[0]['context']['route'])->toBe('login')
        ->and(json_encode($lines[0]))->not->toContain('Juana')->not->toContain('buscar');
});

it('cuenta las consultas de la petición y no las de la anterior', function () {
    Route::get('/_obs/db', function () {
        DB::select('select 1');
        DB::select('select 1');

        return 'ok';
    });
    $calls = captureLog();

    $this->get('/_obs/db');
    $this->get('/_obs/db');

    $lines = doneLines($calls);
    expect($lines)->toHaveCount(2)
        ->and($lines[0]['context']['db_queries'])->toBe(2)
        ->and($lines[1]['context']['db_queries'])->toBe(2);
});

it('mide el tiempo de las llamadas HTTP salientes', function () {
    Http::fake();
    Route::get('/_obs/http', fn () => Http::get('https://solver.test/health')->status());
    $calls = captureLog();

    $this->get('/_obs/http');

    expect(doneLines($calls)[0]['context']['external_ms'])->toBeGreaterThan(0);
});

it('pone el usuario y el puesto activo sin consultar la base', function () {
    $calls = captureLog();
    $user = User::factory()->admin()->create();

    $this->actingAs($user)->withSession(['core.active_role' => 'coordinador'])->get('/login');

    $context = doneLines($calls)[0]['context'];
    expect($context['user_id'])->toBe($user->getKey())
        ->and($context['role'])->toBe('coordinador');
});

it('no escribe línea ni abre sesión para las sondas', function () {
    $calls = captureLog();

    $this->get('/health')->assertHeaderMissing('Set-Cookie');
    $this->get('/up');

    expect(doneLines($calls))->toBeEmpty();
});

it('registra como error una respuesta 5xx', function () {
    Route::get('/_obs/boom', fn () => throw new RuntimeException('boom'));
    $calls = captureLog();

    $this->get('/_obs/boom')->assertStatus(500);

    $line = doneLines($calls)[0];
    expect($line['level'])->toBe('error')->and($line['context']['status'])->toBe(500);
});

it('mide también la llamada saliente que falla', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));
    Route::get('/_obs/http-fail', function () {
        try {
            Http::get('https://solver.test/health');
        } catch (ConnectionException) {
        }

        return 'ok';
    });
    $calls = captureLog();

    $this->get('/_obs/http-fail');

    expect(doneLines($calls)[0]['context']['external_ms'])->toBeGreaterThan(0);
});

it('acumula en la app de la petición y no en la base del worker (Octane)', function () {
    $base = app();
    // Como Octane: la base olvida los scoped antes de clonar, y cada sandbox arranca sin acumulador.
    $base->forgetScopedInstances();
    $sandbox = clone $base;
    CurrentApplication::set($sandbox);

    try {
        expect($sandbox->make(OperationMetrics::class))->not->toBe($base->make(OperationMetrics::class));
        $sandbox->make(OperationMetrics::class)->start();
        DB::select('select 1');
        Http::fake();
        Http::get('https://solver.test/health');

        $metrics = $sandbox->make(OperationMetrics::class);
        expect($metrics->dbQueries())->toBe(1)
            ->and($metrics->externalMs())->toBeGreaterThan(0)
            ->and($base->make(OperationMetrics::class)->dbQueries())->toBe(0);
    } finally {
        CurrentApplication::set($base);
    }
});

it('un job sync no corta ni re-etiqueta la línea de la petición', function () {
    Route::middleware(TagModule::class.':staffing')->get('/_obs/sync-job', function () {
        DB::select('select 1');
        SyncProbeJob::dispatch();
        DB::select('select 1');

        return 'ok';
    });
    $calls = captureLog();

    $this->get('/_obs/sync-job')->assertOk();

    $context = doneLines($calls)[0]['context'];
    expect($context['db_queries'])->toBe(3)
        ->and($context['module'])->toBe('staffing')
        ->and($context['duration_ms'])->toBeGreaterThanOrEqual($context['db_ms'] + $context['external_ms'])
        ->and(array_filter($calls->getArrayCopy(), fn (array $c): bool => str_starts_with($c['message'], 'job.')))->toBeEmpty();
});

class SyncProbeJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        DB::select('select 1');
    }
}
