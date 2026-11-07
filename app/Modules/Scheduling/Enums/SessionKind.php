<?php

namespace App\Modules\Scheduling\Enums;

/**
 * Tipo de una sesión de grupo (docs/dominio/modelo-de-datos.md, `group_sessions`). HC-06 mira las prácticas: una
 * materia con laboratorio solo las tiene en laboratorio, taller o sala de cómputo.
 */
enum SessionKind: string
{
    case Teoria = 'teoria';
    case Practica = 'practica';
    case Virtual = 'virtual';

    /**
     * Nombre para la interfaz.
     */
    public function label(): string
    {
        return match ($this) {
            self::Teoria => 'Teoría',
            self::Practica => 'Práctica',
            self::Virtual => 'Virtual',
        };
    }
}
