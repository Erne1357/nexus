<?php

namespace App\Modules\Scheduling\Jobs;

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Scheduling\Actions\SyncScheduleConflicts;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Recalcula en la cola los choques de la carga de un campus en un periodo (SyncScheduleConflicts; tarea 3.2): lo
 * despacha QueueConflictRecompute al guardar una sesión, un docente o un salón. Uno solo espera en la cola por
 * periodo y campus aunque lleguen varios cambios seguidos; si llega otro mientras corre, se encola de nuevo y no se
 * encima con la corrida en curso. Recalcular es idempotente, así que reintentar es seguro.
 */
final class RecomputeScheduleConflicts implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 600;

    public function __construct(
        public string $termId,
        public string $campusId,
    ) {}

    public function handle(SyncScheduleConflicts $sync): void
    {
        $term = Term::query()->find($this->termId);
        $campus = Campus::query()->find($this->campusId);

        if ($term === null || $campus === null) {
            return;
        }

        $sync->handle($term, $campus);
    }

    public function uniqueId(): string
    {
        return "{$this->termId}:{$this->campusId}";
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("schedule-conflicts:{$this->uniqueId()}"))->releaseAfter(10)->expireAfter($this->timeout)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }
}
