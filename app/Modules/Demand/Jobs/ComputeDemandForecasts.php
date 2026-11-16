<?php

namespace App\Modules\Demand\Jobs;

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Demand\Actions\RefreshDemandForecasts;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Recalcula en la cola la proyección de demanda de un periodo en una modalidad, o solo la de un campus
 * (RefreshDemandForecasts; tarea 5.4). La encolan la apertura del periodo (TermOpened), el recálculo del avance de los
 * alumnos tras importar kárdex (StudentProgressRefreshed) y el cambio de un parámetro de demanda (tarea 5.8).
 *
 * Espera uno solo en la cola por periodo, modalidad y campus aunque lleguen varios avisos seguidos; si llega otro
 * mientras corre, se encola de nuevo, pero no se encima con la corrida en curso: espera a que termine y se reintenta cada
 * 30 segundos durante una hora. Recalcular es idempotente (reemplaza las filas), así que reintentar es seguro; un error
 * de verdad lo deja fallido a la primera. Lleva ids: si el periodo, la modalidad o el campus ya no existen, no hace nada.
 */
final class ComputeDemandForecasts implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 1;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $termId,
        public readonly string $modalityId,
        public readonly ?string $campusId = null,
    ) {}

    public function uniqueId(): string
    {
        return implode('|', [$this->termId, $this->modalityId, $this->campusId ?? 'all']);
    }

    public function handle(RefreshDemandForecasts $refresh): void
    {
        $term = Term::query()->find($this->termId);
        $modality = Modality::query()->find($this->modalityId);
        $campus = $this->campusId === null ? null : Campus::query()->find($this->campusId);

        if ($term === null || $modality === null || ($this->campusId !== null && $campus === null)) {
            return;
        }

        $refresh->handle($term, $modality, $campus);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('demand-forecasts:'.$this->uniqueId()))->releaseAfter(30)->expireAfter($this->timeout)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }
}
