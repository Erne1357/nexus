<?php

namespace App\Modules\Demand\Listeners;

use App\Modules\Core\Events\TermOpened;
use App\Modules\Demand\Jobs\ComputeDemandForecasts;

/**
 * Al abrir un periodo en una modalidad, encola su proyección (tarea 5.4) para que las propuestas nazcan precargadas
 * (5.5). TermOpened corre dentro de la transacción de OpenTerm, así que el job sale después del commit: si la apertura
 * se revierte, no se proyecta nada.
 */
final class QueueForecastsOnTermOpened
{
    public function handle(TermOpened $event): void
    {
        ComputeDemandForecasts::dispatch($event->term->getKey(), $event->modality->getKey())->afterCommit();
    }
}
