<?php

namespace App\Modules\Faculty\Enums;

/**
 * `teacher_availability.kind`: no disponible o preferido.
 */
enum AvailabilityKind: string
{
    case Unavailable = 'unavailable';
    case Preferred = 'preferred';

    public function label(): string
    {
        return match ($this) {
            self::Unavailable => 'No disponible',
            self::Preferred => 'Preferido',
        };
    }
}
