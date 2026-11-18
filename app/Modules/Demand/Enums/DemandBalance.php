<?php

namespace App\Modules\Demand\Enums;

use App\Modules\Core\Enums\StatusTone;

/**
 * Cómo está la oferta de una materia frente a la demanda esperada (RA-04, fase 8): le **falta** oferta, le **sobra** o
 * está **ajustada** dentro del umbral de diferencia de la proyección (`DemandSettings::balanceOf()`).
 */
enum DemandBalance: string
{
    case Shortfall = 'shortfall';
    case Surplus = 'surplus';
    case Adjusted = 'adjusted';

    public function label(): string
    {
        return match ($this) {
            self::Shortfall => 'Faltante',
            self::Surplus => 'Sobrante',
            self::Adjusted => 'Ajustada',
        };
    }

    /**
     * El tono de su insignia (DESIGN.md, «Insignias de estado»): lo que falta es lo que más urge, lo que sobra avisa y
     * lo ajustado está bien.
     */
    public function tone(): StatusTone
    {
        return match ($this) {
            self::Shortfall => StatusTone::Error,
            self::Surplus => StatusTone::Warning,
            self::Adjusted => StatusTone::Success,
        };
    }

    /**
     * El icono de su insignia y de su filtro (el color nunca va solo): lo que falta sube, lo que sobra baja.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Shortfall => 'o-arrow-trending-up',
            self::Surplus => 'o-arrow-trending-down',
            self::Adjusted => 'o-check-circle',
        };
    }

    /**
     * En plural, para los filtros del re-análisis («Faltantes 9»).
     */
    public function plural(): string
    {
        return match ($this) {
            self::Shortfall => 'Faltantes',
            self::Surplus => 'Sobrantes',
            self::Adjusted => 'Ajustadas',
        };
    }
}
