<?php

namespace App\Modules\Demand\Listeners;

use App\Modules\Demand\Jobs\ComputeDemandForecasts;
use App\Modules\Demand\Queries\ForecastTargetsQuery;
use App\Modules\Students\Events\StudentProgressRefreshed;

/**
 * Cuando se recalcula el avance de los alumnos (después de importar alumnos, kárdex, materias o seriaciones), vuelve a
 * proyectar cada periodo abierto que se planea, en cada modalidad (ForecastTargetsQuery; tarea 5.4). El job es único por
 * periodo y modalidad, así que varias importaciones seguidas dejan un solo recálculo en la cola.
 */
final class QueueForecastsOnProgressRefreshed
{
    public function __construct(private readonly ForecastTargetsQuery $targets) {}

    public function handle(StudentProgressRefreshed $event): void
    {
        foreach ($this->targets->openings() as $opening) {
            ComputeDemandForecasts::dispatch($opening->term_id, $opening->modality_id);
        }
    }
}
