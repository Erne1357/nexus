<?php

namespace App\Modules\Solver\Listeners;

use App\Modules\Scheduling\Events\NewGroupPlacementRequested;
use App\Modules\Solver\Queries\NewGroupSlotQuery;

/**
 * Contesta dónde cabe el grupo nuevo de una sugerencia del re-análisis (tarea 8.3b): Scheduling no ve al motor, así que lo
 * pide con NewGroupPlacementRequested y Solver lo coloca con la heurística (NewGroupSlotQuery). Solo lee.
 */
final class ProvideNewGroupPlacement
{
    public function __construct(private readonly NewGroupSlotQuery $slots) {}

    public function handle(NewGroupPlacementRequested $event): void
    {
        $event->providePlacement($this->slots->place($event->load, $event->spec));
    }
}
