<?php

namespace App\Modules\Core\Enums;

/**
 * El motivo de «Dar de baja» una cuenta del personal (CF-05; la lista es supuesto por confirmar). No es una columna:
 * DeactivateUser lo guarda en la bitácora (`properties.reason`), con una nota opcional.
 */
enum DeactivationReason: string
{
    case Renuncia = 'renuncia';
    case Jubilacion = 'jubilacion';
    case CambioDeArea = 'cambio_de_area';
    case FinDeNombramiento = 'fin_de_nombramiento';
    case Otro = 'otro';

    /**
     * Nombre para la interfaz.
     */
    public function label(): string
    {
        return match ($this) {
            self::Renuncia => 'Renuncia',
            self::Jubilacion => 'Jubilación',
            self::CambioDeArea => 'Cambio de área',
            self::FinDeNombramiento => 'Fin de nombramiento',
            self::Otro => 'Otro',
        };
    }
}
