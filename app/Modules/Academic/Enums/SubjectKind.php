<?php

namespace App\Modules\Academic\Enums;

/**
 * Tipo de materia (`subjects.kind`). La exportación del SII no lo trae: queda vacío hasta que se capture.
 */
enum SubjectKind: string
{
    case Basica = 'basica';
    case Especialidad = 'especialidad';
    case Complementaria = 'complementaria';
    case Residencia = 'residencia';
    case ServicioSocial = 'servicio_social';
    case LenguaExtranjera = 'lengua_extranjera';

    public function label(): string
    {
        return match ($this) {
            self::Basica => 'Básica',
            self::Especialidad => 'Especialidad',
            self::Complementaria => 'Complementaria',
            self::Residencia => 'Residencia profesional',
            self::ServicioSocial => 'Servicio social',
            self::LenguaExtranjera => 'Lengua extranjera',
        };
    }
}
