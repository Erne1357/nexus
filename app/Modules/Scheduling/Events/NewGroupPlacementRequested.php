<?php

namespace App\Modules\Scheduling\Events;

use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Scheduling\Rules\Reanalysis\NewGroupPlacement;
use App\Modules\Scheduling\Rules\Reanalysis\NewGroupSpec;
use LogicException;

/**
 * El drawer de sugerencias del re-análisis pide dónde cabe un grupo nuevo (tarea 8.3b, «Abrir un grupo»). Scheduling no
 * ve al motor (Solver está a su derecha en el mapa de módulos), así que lo contesta Solver al atender este evento, con la
 * forma de LoadSnapshotRequested: arma la entrada de la carga con todos sus grupos fijos más el grupo nuevo (`spec`) y
 * corre la heurística. Se despacha fuera de toda transacción y quien contesta solo lee. Sin respuesta (el motor no está),
 * `placement()` es null y la tarjeta lo dice.
 */
final class NewGroupPlacementRequested
{
    private ?NewGroupPlacement $placement = null;

    public function __construct(
        public readonly AcademicLoad $load,
        public readonly NewGroupSpec $spec,
    ) {}

    /**
     * @throws LogicException si otro ya contestó: el lugar del grupo nuevo tiene una sola fuente
     */
    public function providePlacement(NewGroupPlacement $placement): void
    {
        if ($this->placement !== null) {
            throw new LogicException('El lugar del grupo nuevo ya lo dio otro módulo.');
        }

        $this->placement = $placement;
    }

    /**
     * Null si nadie contestó.
     */
    public function placement(): ?NewGroupPlacement
    {
        return $this->placement;
    }
}
