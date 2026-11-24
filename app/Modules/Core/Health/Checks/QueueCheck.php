<?php

namespace App\Modules\Core\Health\Checks;

use App\Modules\Core\Health\Check;
use App\Modules\Core\Health\CheckResult;
use App\Modules\Core\Queries\QueueSizesQuery;
use Illuminate\Support\Facades\Redis;

final class QueueCheck implements Check
{
    public function __construct(private readonly QueueSizesQuery $sizes) {}

    public function name(): string
    {
        return 'queue';
    }

    public function critical(): bool
    {
        return true;
    }

    public function run(): CheckResult
    {
        Redis::connection(config('queue.connections.redis.connection'))->ping();

        $detail = [];

        foreach ($this->sizes->all(QueueSizesQuery::WORKER_QUEUES) as $queue) {
            $detail[$queue['queue']] = ['waiting' => $queue['waiting'], 'delayed' => $queue['delayed'], 'reserved' => $queue['reserved']];
        }

        return CheckResult::ok($detail);
    }
}
