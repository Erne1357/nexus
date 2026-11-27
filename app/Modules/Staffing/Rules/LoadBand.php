<?php

namespace App\Modules\Staffing\Rules;

use App\Modules\Faculty\Rules\TeachingHours;

/**
 * SEL-05. Banda de carga de un docente (no aplica a quien tiene plaza administrativa, PL-03): de sus horas frente a grupo a más o menos 2 horas (art. 147), inclusiva. Puro.
 */
final readonly class LoadBand
{
    public const MARGIN = TeachingHours::MARGIN;

    /**
     * PL-03: quien es de plaza administrativa (`$administrative`) no lleva banda: siempre queda dentro y nunca va a la
     * ronda extra por «bajo».
     *
     * @return 'within'|'over'|'under'
     */
    public static function classify(int $hours, int $base, bool $administrative = false): string
    {
        return match (true) {
            $administrative => 'within',
            $hours > $base + self::MARGIN => 'over',
            $hours < $base - self::MARGIN => 'under',
            default => 'within',
        };
    }
}
