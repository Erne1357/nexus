<?php

namespace App\Modules\Solver\Enums;

/**
 * Qué programa una corrida del motor (decisión del usuario, 17 de noviembre de 2026): la carga de un departamento en
 * una modalidad, en todos sus campus, que el jefe acepta; o los grupos de una carrera, que el coordinador lanza para
 * explorar y comparar, sin aceptarla.
 */
enum RunScope: string
{
    case Department = 'department';
    case Career = 'career';

    public function label(): string
    {
        return match ($this) {
            self::Department => 'Departamento',
            self::Career => 'Carrera',
        };
    }
}
