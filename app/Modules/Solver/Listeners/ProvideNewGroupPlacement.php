<?php

namespace App\Modules\Solver\Listeners;

use App\Modules\Scheduling\Events\NewGroupPlacementRequested;
use App\Modules\Scheduling\Rules\Reanalysis\NewGroupPlacement;
use App\Modules\Solver\Queries\NewGroupSlotQuery;
use Throwable;

/**
 * Contesta dónde cabe el grupo nuevo de una sugerencia del re-análisis (tarea 8.3b): Scheduling no ve al motor, así que lo
 * pide con NewGroupPlacementRequested y Solver lo coloca con la heurística (NewGroupSlotQuery). Solo lee. Si el motor
 * falla, el error queda registrado (`report()`) y la tarjeta dice que no hay lugar, en vez de un error de la página.
 */
final class ProvideNewGroupPlacement
{
    public function __construct(private readonly NewGroupSlotQuery $slots) {}

    public function handle(NewGroupPlacementRequested $event): void
    {
        try {
            $placement = $this->slots->place($event->load, $event->spec);
        } catch (Throwable $exception) {
            report($exception);
            $placement = new NewGroupPlacement([], reasons: ['El motor falló al buscar el lugar y ya quedó registrado: vuelve a intentar o ábrelo en Horarios.']);
        }

        $event->providePlacement($placement);
    }
}
