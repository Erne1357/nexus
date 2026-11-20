<?php

namespace App\Modules\Core\Enums;

/**
 * El tipo de personal de un usuario (R-V2-3, confirmado el 19 de noviembre de 2026): docente o administrativo. Es
 * aparte de los puestos (`Role`): un docente puede tener cargos administrativos y un administrativo dar clases, y
 * aun así importa qué tipo de personal es. Nulo es «sin tipo» (alumnos, cuentas del panel que nadie ha clasificado).
 */
enum StaffType: string
{
    case Docente = 'docente';
    case Administrativo = 'administrativo';

    public function label(): string
    {
        return match ($this) {
            self::Docente => 'Docente',
            self::Administrativo => 'Administrativo',
        };
    }
}
