<?php

namespace App\Modules\AcademicLoad\Enums;

use App\Modules\Core\Enums\StatusTone;

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

    /**
     * El tono de su insignia en «Precisión de la proyección» (DESIGN.md, «Insignias de estado»).
     */
    public function tone(): StatusTone
    {
        return match ($this) {
            self::Strong => StatusTone::Success,
            self::Reference => StatusTone::Warning,
            self::Unmeasured => StatusTone::Draft,
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Strong => 'o-check-badge',
            self::Reference => 'o-information-circle',
            self::Unmeasured => 'o-minus-circle',
        };
    }
}
