<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Core\Authorization\Actor;

/**
 * Lo que el editor de horarios muestra «por colocar» cuando llega con `?solicitud=`: las sesiones que alguien ya
 * decidió para unos grupos y falta escribir (tarea 8.7: la alternativa del motor elegida en una solicitud de cambio).
 * Scheduling no conoce las solicitudes, que están a su derecha en el mapa de módulos: liga NoPendingPlacements, y
 * Staffing lo reemplaza en su register() con la alternativa elegida.
 */
interface PendingPlacements
{
    /**
     * Lo que hay por colocar con esa llave para quien lo ve, o null si no hay nada (o no le toca verlo).
     */
    public function for(Actor $context, string $key): ?PendingPlacementSet;
}
