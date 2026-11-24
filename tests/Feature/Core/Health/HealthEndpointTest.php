<?php

use App\Modules\Core\Enums\HealthStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/HealthTestHelpers.php';

/*
| El /health profundo (tarea 1.31, spec §6.1): readiness fuera del grupo `web`.
*/

beforeEach(function () {
    healthFreshHeartbeats();
    config(['services.solver.url' => null, 'health.release' => 'abc123', 'health.token' => 'secreto', 'health.drain_file' => sys_get_temp_dir().'/nexus-draining-test-'.bin2hex(random_bytes(4))]);
});

it('responde ok con todo sano y sin cookies', function () {
    $response = $this->getJson('/health');

    $response->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('release', 'abc123')
        ->assertHeaderMissing('Set-Cookie');

    expect(array_keys($response->json('checks')))
        ->toBe(['database', 'cache', 'queue', 'storage', 'migrations', 'solver', 'queue_worker', 'scheduler']);
});

it('sin token solo muestra estados', function () {
    $this->getJson('/health')
        ->assertOk()
        ->assertJsonMissingPath('checks.queue.detail')
        ->assertJsonMissingPath('checks.database.detail');
});

it('con el token muestra el detalle', function () {
    $this->getJson('/health', ['X-Health-Token' => 'secreto'])
        ->assertOk()
        ->assertJsonPath('checks.queue.detail.default.waiting', 0);

    $this->getJson('/health', ['X-Health-Token' => 'otro'])
        ->assertOk()
        ->assertJsonMissingPath('checks.queue.detail');
});

it('no muestra detalle si no hay token configurado', function () {
    config(['health.token' => null]);

    $this->getJson('/health', ['X-Health-Token' => ''])->assertJsonMissingPath('checks.queue.detail');
});

it('responde 503 en JSON si la base de datos no responde', function () {
    config([
        'database.connections.health_broken' => [...config('database.connections.pgsql'), 'host' => '127.0.0.1', 'port' => 1, 'url' => null],
        'health.database_connection' => 'health_broken',
    ]);

    $this->get('/health', ['Accept' => 'text/html'])
        ->assertStatus(503)
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('status', 'fail')
        ->assertJsonPath('checks.database.status', 'fail')
        ->assertJsonMissingPath('checks.database.detail');
});

it('responde 503 en JSON si la caché no responde', function () {
    config([
        'database.redis.health_broken' => ['host' => '127.0.0.1', 'port' => 1, 'database' => 0, 'max_retries' => 0, 'timeout' => 0.5],
        'cache.stores.health_broken' => ['driver' => 'redis', 'connection' => 'health_broken', 'lock_connection' => 'health_broken'],
        'health.cache_store' => 'health_broken',
    ]);

    $this->get('/health', ['Accept' => 'text/html'])
        ->assertStatus(503)
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('checks.cache.status', 'fail');
});

it('falla si hay migraciones pendientes', function () {
    $directory = healthTempDirectory();
    healthFakeMigration($directory);
    app('migrator')->path($directory);

    $this->getJson('/health', ['X-Health-Token' => 'secreto'])
        ->assertStatus(503)
        ->assertJsonPath('checks.migrations.status', 'fail')
        ->assertJsonPath('checks.migrations.detail.pending.0', '2999_01_01_000000_health_pending');
});

it('queda degradado si la base tiene migraciones que el código no conoce', function () {
    DB::table('migrations')->insert(['migration' => '2998_01_01_000000_de_una_imagen_nueva', 'batch' => 99]);

    $this->getJson('/health')
        ->assertOk()
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.migrations.status', 'degraded');
});

it('falla si dos módulos tienen una migración con el mismo nombre', function () {
    $existing = (string) DB::table('migrations')->orderBy('id')->value('migration');
    $directory = healthTempDirectory();
    healthFakeMigration($directory, $existing);
    app('migrator')->path($directory);

    $this->getJson('/health')
        ->assertStatus(503)
        ->assertJsonPath('checks.migrations.status', 'fail');
});

it('responde draining a la sonda del balanceador mientras existe el archivo de drenado', function () {
    touch(config('health.drain_file'));

    try {
        $this->getJson('/health', ['X-Health-Probe' => 'lb'])
            ->assertStatus(503)
            ->assertJsonPath('status', HealthStatus::Draining->value)
            ->assertJsonMissingPath('checks');
    } finally {
        unlink(config('health.drain_file'));
    }
});

it('sigue respondiendo normal al público mientras drena', function () {
    touch(config('health.drain_file'));

    try {
        $this->getJson('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('draining', true)
            ->assertJsonStructure(['checks' => ['database']]);

        $this->getJson('/health', ['X-Health-Probe' => 'otro'])->assertOk();
    } finally {
        unlink(config('health.drain_file'));
    }
});

it('queda degradado si el motor no responde', function () {
    config(['services.solver.url' => 'http://solver.invalid:9']);
    Http::fake(fn () => throw new ConnectionException('sin ruta'));

    $this->getJson('/health')
        ->assertOk()
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.solver.status', 'degraded');
});

it('el motor sin URL configurada cuenta como ok', function () {
    config(['services.solver.url' => null]);

    $this->getJson('/health', ['X-Health-Token' => 'secreto'])
        ->assertJsonPath('checks.solver.status', 'ok')
        ->assertJsonPath('checks.solver.detail.configured', false);
});
