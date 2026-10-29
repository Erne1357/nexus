<?php

namespace App\Modules\Students\Enums;

/**
 * Estatus escolar de un alumno, como lo trae el SII (`students.status`). La lista exacta del SII no se
 * conoce todavía: un estatus que no está aquí entra vacío y con advertencia, y los que se repitan en las
 * exportaciones reales se agregan (confirmado el 29 de octubre de 2026).
 */
enum StudentStatus: string
{
    case Activo = 'activo';
    case BajaTemporal = 'baja_temporal';
    case BajaDefinitiva = 'baja_definitiva';
    case Egresado = 'egresado';

    public function label(): string
    {
        return match ($this) {
            self::Activo => 'Activo',
            self::BajaTemporal => 'Baja temporal',
            self::BajaDefinitiva => 'Baja definitiva',
            self::Egresado => 'Egresado',
        };
    }
}
