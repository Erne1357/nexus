<?php

namespace App\Modules\Facilities\Enums;

/**
 * Origen de un salón por defecto de una materia: capturado a mano o sugerido por el historial de cargas.
 */
enum RoomDefaultSource: string
{
    case Manual = 'manual';
    case Historial = 'historial';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Historial => 'Historial',
        };
    }
}
