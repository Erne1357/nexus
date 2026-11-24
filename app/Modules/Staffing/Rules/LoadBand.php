<?php

namespace App\Modules\Staffing\Rules;

/**
 * SEL-05. Banda de carga de un docente: de la base a más o menos 2 horas, inclusiva. Puro.
 */
final readonly class LoadBand
{
    public const MARGIN = 2;

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
