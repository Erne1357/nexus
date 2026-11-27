<?php

namespace App\Modules\Students\Enums;

use App\Modules\Core\Enums\StatusTone;

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

    /**
     * Tono de su insignia (DESIGN.md, "Insignias de estado"). Un alumno sin estatus lleva la de borrador.
     */
    public function tone(): StatusTone
    {
        return match ($this) {
            self::Activo => StatusTone::Success,
            self::BajaTemporal => StatusTone::Warning,
            self::BajaDefinitiva => StatusTone::Neutral,
            self::Egresado => StatusTone::Info,
        };
    }

    /**
     * Icono de su insignia cuando no es el de su tono: la baja temporal espera a que regrese y el egresado ya
     * terminó; null deja el del tono.
     */
    public function icon(): ?string
    {
        return match ($this) {
            self::BajaTemporal => 'o-clock',
            self::Egresado => 'o-academic-cap',
            default => null,
        };
    }
}
