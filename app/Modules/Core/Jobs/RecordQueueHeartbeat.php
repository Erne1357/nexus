<?php

namespace App\Modules\Core\Jobs;

use App\Modules\Core\Health\Checks\HeartbeatCheck;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * El scheduler lo manda cada minuto: si `/health` ve este latido reciente, el worker de la cola está procesando.
 */
final class RecordQueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        Cache::store(config('health.cache_store'))->put(HeartbeatCheck::QUEUE_KEY, now()->timestamp, 600);
    }
}
