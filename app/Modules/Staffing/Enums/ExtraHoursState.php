<?php

namespace App\Modules\Staffing\Enums;

use App\Modules\Scheduling\Rules\TeacherHourLimit;

/**
 * PL-04: el estado de las horas extra de un docente en un periodo. «Por autorizar» es el de omisión y el de una decisión
 * que se reabrió porque subieron las horas; «jefatura» es el de quien no puede tener honorarios (jefe de departamento,
 * subdirección o dirección): sus horas extra van directo al oficio de conformidad (pendiente) y nadie las autoriza.
 */
enum ExtraHoursState: string
{
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Unauthorized = 'unauthorized';
    case Jefatura = 'jefatura';

    /** «2 h extra · honorarios por autorizar (jefe de depto)» y compañía. */
    public function describe(int $extra): string
    {
        return TeacherHourLimit::extraLabel($extra, $this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Authorized => 'success',
            self::Unauthorized, self::Jefatura => 'draft',
        };
    }

    /** El jefe puede decidir sobre estas horas (no es de jefatura). */
    public function isDecidable(): bool
    {
        return $this !== self::Jefatura;
    }
}
