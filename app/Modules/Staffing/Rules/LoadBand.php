<?php

namespace App\Modules\Staffing\Rules;

use App\Modules\Faculty\Rules\TeachingHours;

/**
 * SEL-05. Banda de carga de un docente: de sus horas frente a grupo a más o menos 2 horas (art. 147), inclusiva. Puro.
 */
final readonly class LoadBand
{
    public const MARGIN = TeachingHours::MARGIN;

    /**
     * @return 'within'|'over'|'under'
     */
    public static function classify(int $hours, int $base): string
    {
        return match (true) {
            $hours > $base + self::MARGIN => 'over',
            $hours < $base - self::MARGIN => 'under',
            default => 'within',
        };
    }
}
