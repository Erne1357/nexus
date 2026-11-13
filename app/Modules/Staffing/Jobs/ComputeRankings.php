<?php

namespace App\Modules\Staffing\Jobs;

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Staffing\Actions\RankGroups;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Recalcula en la cola el ranking docente de un departamento en un periodo y modalidad, con los grupos de todos sus
 * campus (RankGroups, M1). Lo encola QueueRankings desde los listeners de Staffing cuando cambia una entrada del
 * ranking: grupos de Scheduling o datos del docente en Faculty.
 *
 * Espera uno solo por alcance en la cola (`term:modality:department`), con un candado que vence en una hora por si nunca
 * llega a correr; como se encola con unos segundos de retraso, una ráfaga de cambios espera un solo recálculo. Si llega
 * otro cambio mientras corre, se encola de nuevo, pero no se encima con la corrida en curso del mismo alcance: espera
 * a que termine y se reintenta cada 10 segundos durante una hora. Recalcular es idempotente; un error de verdad lo
 * deja fallido a la primera (`maxExceptions`). Si el periodo, la modalidad o el departamento ya no existen (borrados),
 * no hace nada.
 */
final class ComputeRankings implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 1;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    public function __construct(
        public string $termId,
        public string $modalityId,
        public string $departmentId,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->termId}:{$this->modalityId}:{$this->departmentId}";
    }

    public function handle(RankGroups $rank): void
    {
        $term = Term::query()->find($this->termId);
        $modality = Modality::query()->find($this->modalityId);
        $department = Department::query()->find($this->departmentId);

        if ($term === null || $modality === null || $department === null) {
            return;
        }

        $rank->handle($term, $modality, $department);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->releaseAfter(10)->expireAfter($this->timeout)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }
}
