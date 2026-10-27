<?php

namespace App\Modules\Core\Enums;

/**
 * Quién asigna docentes en un campus (perfil de flujo, ADR-0009): cada jefe de departamento
 * o una instancia central. R1 y R2 solo implementan `department` (presencial).
 */
enum StaffingMode: string
{
    case Department = 'department';
    case Central = 'central';

    /**
     * Nombre para la interfaz.
     */
    public function label(): string
    {
        return match ($this) {
            self::Department => 'Por jefe de departamento',
            self::Central => 'Central',
        };
    }
}
