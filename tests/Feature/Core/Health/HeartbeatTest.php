<?php

use App\Modules\Core\Jobs\RecordQueueHeartbeat;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/HealthTestHelpers.php';

/*
| Los latidos del scheduler y de la cola que lee /health (tarea 1.31).
*/

it('queda degradado si el latido del scheduler tiene más de tres minutos', function () {
    healthFreshHeartbeats();
    $this->travel(4)->minutes();
    Cache::put('health:heartbeat:queue', now()->timestamp, 600);

    $this->getJson('/health')
        ->assertOk()
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.scheduler.status', 'degraded')
        ->assertJsonPath('checks.queue_worker.status', 'ok');
});

it('queda degradado si nunca hubo latidos', function () {
    $this->getJson('/health')
        ->assertOk()
        ->assertJsonPath('checks.scheduler.status', 'degraded')
        ->assertJsonPath('checks.queue_worker.status', 'degraded');
});

it('el job escribe el latido de la cola', function () {
    expect(Cache::get('health:heartbeat:queue'))->toBeNull();

    (new RecordQueueHeartbeat)->handle();

    expect(Cache::get('health:heartbeat:queue'))->toBe(now()->timestamp);
});

it('el scheduler registra el latido cada minuto', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => $event->description === 'health-heartbeat' && $event->expression === '* * * * *');

    expect($events)->toHaveCount(1);
});
