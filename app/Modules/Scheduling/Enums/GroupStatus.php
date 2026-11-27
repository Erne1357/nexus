<?php

namespace App\Modules\Scheduling\Enums;

/**
 * Estado de un grupo (docs/dominio/modelo-de-datos.md, `groups`). Nace planeado; confirmado es el que se va a
 * abrir; cancelado deja de contar para el validador y para los empalmes de la base, pero no se borra (HC-15).
 */
enum GroupStatus: string
{
    case Planned = 'planned';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    /**
     * Nombre para la interfaz.
     */
    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planeado',
            self::Confirmed => 'Confirmado',
            self::Cancelled => 'Cancelado',
        };
    }
}
