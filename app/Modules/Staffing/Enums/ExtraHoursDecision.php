<?php

namespace App\Modules\Staffing\Enums;

/**
 * PL-04: lo que decide el jefe de departamento sobre las horas extra de un docente en un periodo.
 */
enum ExtraHoursDecision: string
{
    case Authorized = 'authorized';
    case Unauthorized = 'unauthorized';

    public function label(): string
    {
        return match ($this) {
            self::Authorized => 'Autorizadas como honorarios',
            self::Unauthorized => 'No autorizadas',
        };
    }
}
