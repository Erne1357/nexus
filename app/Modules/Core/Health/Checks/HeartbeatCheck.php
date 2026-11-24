<?php

namespace App\Modules\Core\Health\Checks;

use App\Modules\Core\Health\Check;
use App\Modules\Core\Health\CheckResult;
use Illuminate\Support\Facades\Cache;

/**
 * El scheduler escribe un latido cada minuto y despacha un job que escribe otro al procesarse (RecordQueueHeartbeat):
 * uno por llave. Sin latido o con más de `health.heartbeat_stale_seconds`, `degraded`.
 */
final class HeartbeatCheck implements Check
{
    public const string SCHEDULER_KEY = 'health:heartbeat:scheduler';

    public const string QUEUE_KEY = 'health:heartbeat:queue';

    public function __construct(private readonly string $name, private readonly string $key) {}

    public function name(): string
    {
        return $this->name;
    }

    public function critical(): bool
    {
        return false;
    }

    public function run(): CheckResult
    {
        $beat = Cache::store(config('health.cache_store'))->get($this->key);

        if ($beat === null) {
            return CheckResult::degraded('Sin latido registrado.');
        }

        $age = now()->timestamp - (int) $beat;

        return $age > (int) config('health.heartbeat_stale_seconds')
            ? CheckResult::degraded('Último latido hace '.$age.' s.', ['age_seconds' => $age])
            : CheckResult::ok(['age_seconds' => $age]);
    }
}
