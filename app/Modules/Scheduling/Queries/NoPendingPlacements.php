<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Core\Authorization\Actor;

/**
 * Sin un módulo que lo reemplace, el editor no tiene nada por colocar (PendingPlacements).
 */
final class NoPendingPlacements implements PendingPlacements
{
    public function for(Actor $context, string $key): ?PendingPlacementSet
    {
        return null;
    }
}
