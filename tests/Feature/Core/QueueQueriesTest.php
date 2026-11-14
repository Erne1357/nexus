<?php

use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\FailedJobsQuery;
use App\Modules\Core\Queries\PendingInvitationsQuery;
use App\Modules\Core\Queries\QueueSizesQuery;
use App\Modules\Core\Support\AccessInvitationLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/*
| Las lecturas de «Correos y colas» (CF-S8): invitaciones pendientes con su vencimiento, trabajos fallidos y
| tamaños de las colas.
*/

/**
 * Un renglón de `failed_jobs`.
 */
function failedJob(string $displayName = 'App\\Jobs\\Demo', string $queue = 'default', string $exception = "RuntimeException: boom\n#0 uno\n#1 dos\n#2 tres\n#3 cuatro", string $failedAt = '2026-11-14 10:00:00'): string
{
    $uuid = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => $queue,
        'payload' => json_encode(['displayName' => $displayName, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => []]),
        'exception' => $exception,
        'failed_at' => $failedAt,
    ]);

    return $uuid;
}

it('CF-S8: las invitaciones pendientes son de usuarios activos sin contraseña con invitación, con su vencimiento', function () {
    $invited = User::factory()->create(['password' => null, 'email' => 'a@itcj.test', 'access_invited_at' => now()->subDays(2)->startOfSecond()]);
    $expired = User::factory()->create(['password' => null, 'email' => 'b@itcj.test', 'access_invited_at' => now()->subDays(9)->startOfSecond()]);
    User::factory()->create(['password' => null, 'access_invited_at' => null]);
    User::factory()->create(['access_invited_at' => now()->subDay()]);
    User::factory()->create(['password' => null, 'is_active' => false, 'access_invited_at' => now()->subDay()]);

    $rows = app(PendingInvitationsQuery::class)->all();
    $link = app(AccessInvitationLink::class);

    expect($rows->pluck('user.id')->all())->toBe([$expired->id, $invited->id])
        ->and($rows[0]['expired'])->toBeTrue()
        ->and($rows[1]['expired'])->toBeFalse()
        ->and($rows[1]['expires_at']->equalTo($link->expiresAt($invited)))->toBeTrue();
});

it('CF-S8: los trabajos fallidos traen cola, nombre, hora y las primeras líneas de la excepción', function () {
    failedJob('App\\Jobs\\Viejo', 'default', 'x', '2026-11-13 10:00:00');
    $uuid = failedJob('App\\Jobs\\Nuevo', 'bulk');

    $page = app(FailedJobsQuery::class)->forTable();
    $first = $page->first();

    expect($page->total())->toBe(2)
        ->and($first->uuid)->toBe($uuid)
        ->and($first->queue)->toBe('bulk')
        ->and($first->job_name)->toBe('App\\Jobs\\Nuevo')
        ->and($first->failed_at->format('Y-m-d H:i'))->toBe('2026-11-14 10:00')
        ->and($first->exception_head)->toBe("RuntimeException: boom\n#0 uno\n#1 dos");
});

it('CF-S8: un trabajo fallido sin nombre en el payload se llama «Trabajo desconocido»', function () {
    $uuid = failedJob();
    DB::table('failed_jobs')->where('uuid', $uuid)->update(['payload' => '{}']);

    expect(app(FailedJobsQuery::class)->findOrFail($uuid)->job_name)->toBe('Trabajo desconocido');
});

it('CF-S8: cuenta lo que espera, lo diferido y lo reservado en cada cola de la conexión redis', function () {
    $prefix = 'cfg-g-'.Str::random(6);
    $queue = app('queue')->connection('redis');
    $queue->pushRaw('{"id":"1"}', $prefix);
    $queue->pushRaw('{"id":"2"}', $prefix);
    $queue->later(300, 'App\\Jobs\\Demo', [], $prefix);

    try {
        [$size] = app(QueueSizesQuery::class)->all([$prefix]);

        expect($size['queue'])->toBe($prefix)
            ->and($size['waiting'])->toBe(2)
            ->and($size['delayed'])->toBe(1)
            ->and($size['reserved'])->toBe(0);
    } finally {
        Redis::connection(config('queue.connections.redis.connection', 'default'))->del(["queues:{$prefix}", "queues:{$prefix}:delayed", "queues:{$prefix}:reserved", "queues:{$prefix}:notify"]);
    }
});

it('CF-S8: por omisión cuenta las colas del worker, default y bulk', function () {
    expect(array_column(app(QueueSizesQuery::class)->all(), 'queue'))->toBe(['default', 'bulk']);
});
