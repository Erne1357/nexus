<?php

namespace App\Modules\Core\Queries;

use Illuminate\Contracts\Queue\Factory;
use Illuminate\Queue\RedisQueue;

/**
 * Cuántos trabajos hay en cada cola de la conexión `redis` (CF-S8): los que esperan, los diferidos (con retardo o
 * esperando un reintento) y los reservados (en proceso). Las colas son las que atiende el worker (`queue:work redis
 * --queue=default,bulk`, compose.yaml) más la de la configuración.
 */
final class QueueSizesQuery
{
    /** La conexión que atiende el worker. */
    public const string CONNECTION = 'redis';

    /** Las colas que atiende el worker, en su orden de prioridad (compose.yaml). */
    public const array WORKER_QUEUES = ['default', 'bulk'];

    public function __construct(private readonly Factory $queues) {}

    /**
     * @param  list<string>|null  $names  las colas que se cuentan; por omisión, las del worker
     * @return list<array{queue: string, waiting: int, delayed: int, reserved: int}>
     */
    public function all(?array $names = null): array
    {
        $names ??= array_values(array_unique([...self::WORKER_QUEUES, (string) config('queue.connections.redis.queue', 'default')]));
        $connection = $this->queues->connection(self::CONNECTION);

        return array_map(fn (string $queue): array => [
            'queue' => $queue,
            'waiting' => $connection instanceof RedisQueue ? $connection->pendingSize($queue) : $connection->size($queue),
            'delayed' => $connection instanceof RedisQueue ? $connection->delayedSize($queue) : 0,
            'reserved' => $connection instanceof RedisQueue ? $connection->reservedSize($queue) : 0,
        ], $names);
    }
}
