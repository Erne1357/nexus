<?php

namespace App\Modules\Demand\Listeners;

use App\Modules\Core\Events\SettingsUpdated;
use App\Modules\Demand\Jobs\ComputeDemandForecasts;
use App\Modules\Demand\Queries\ForecastTargetsQuery;

/**
 * Al guardar un parámetro de demanda (`demand.*`), vuelve a proyectar en segundo plano cada periodo abierto que se
 * planea, en cada modalidad (ForecastTargetsQuery; confirmado el 16 de noviembre de 2026). SettingsUpdated sale ya
 * confirmada la transacción y solo con las llaves que cambiaron, así que guardar sin cambios no encola nada. El job es
 * único por periodo y modalidad.
 */
final class RecalculateForecastsOnSettingsUpdated
{
    public function __construct(private readonly ForecastTargetsQuery $targets) {}

    public function handle(SettingsUpdated $event): void
    {
        if (! collect($event->keys)->contains(fn (string $key): bool => str_starts_with($key, 'demand.'))) {
            return;
        }

        foreach ($this->targets->openings() as $opening) {
            ComputeDemandForecasts::dispatch($opening->term_id, $opening->modality_id);
        }
    }
}
