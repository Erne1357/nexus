<?php

namespace App\Modules\Scheduling\Jobs;

use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Scheduling\Actions\SyncScheduleConflicts;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Recalcula en la cola los choques de la carga de una modalidad en un periodo, con los grupos de todos sus campus
 * (SyncScheduleConflicts; tarea 3.2, M1): lo despacha QueueConflictRecompute al guardar una sesión, un docente, un
 * salón o el perfil de la modalidad. Uno solo espera en la cola por periodo y modalidad aunque lleguen varios cambios
 * seguidos; si llega otro mientras corre, se encola de nuevo y no se encima con la corrida en curso. Recalcular es
 * idempotente, así que reintentar es seguro. El tiempo límite (300 s) deja mucho margen sobre el recálculo más grande
 * del masivo (Presencial de Ene-Jun 2027, 3 611 grupos y 9 744 sesiones: 1.5 s el 13 de noviembre de 2026) y vence
 * antes del `retry_after` de Redis.
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
        public string $modalityId,
    ) {}

    public function handle(SyncScheduleConflicts $sync): void
    {
        $term = Term::query()->find($this->termId);
        $modality = Modality::query()->withTrashed()->find($this->modalityId);

        if ($term === null || $modality === null) {
            return;
        }

        $sync->handle($term, $modality);
    }

    public function uniqueId(): string
    {
        return "{$this->termId}:{$this->modalityId}";
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
