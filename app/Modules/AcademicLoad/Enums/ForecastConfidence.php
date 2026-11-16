<?php

namespace App\Modules\AcademicLoad\Enums;

/**
 * Qué tanto se puede confiar en la proyección de una carrera según su última evaluación (DM-22; confirmado el 16 de
 * noviembre de 2026): sugerencia fuerte, referencia, o sin medir si no hay evaluación o todo lo real fue cero.
 */
enum ForecastConfidence: string
{
    case Strong = 'strong';
    case Reference = 'reference';
    case Unmeasured = 'unmeasured';

    public function label(): string
    {
        return match ($this) {
            self::Strong => 'Sugerencia fuerte',
            self::Reference => 'Referencia',
            self::Unmeasured => 'Sin medir',
        };
    }
}
