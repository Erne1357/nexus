<?php

namespace App\Modules\Core\Enums;

/**
 * Forma de impartición de un campus (glosario). Cambia qué pasos del flujo aplican a través del
 * perfil de flujo; R1 y R2 implementan solo `presencial`.
 *
 * Sale al contraer la carga por modalidad (ADR-0025): la modalidad pasa al catálogo `modalities`.
 */
enum Modality: string
{
    case Presencial = 'presencial';
    case Mixta = 'mixta';
    case EnLinea = 'en_linea';
    case VirtualTecnm = 'virtual_tecnm';

    /**
     * Nombre para la interfaz (glosario).
     */
    public function label(): string
    {
        return match ($this) {
            self::Presencial => 'Presencial',
            self::Mixta => 'Mixta',
            self::EnLinea => 'En línea',
            self::VirtualTecnm => 'TecNM Virtual',
        };
    }

    /**
     * Su clave en el catálogo `modalities` (ADR-0025): puente temporal para llenar `campuses.modality_id`, que se quita
     * al contraer. Mixta es EAD, que reúne lo que el SII llama «a distancia» y «mixta» (M3).
     */
    public function catalogCode(): string
    {
        return match ($this) {
            self::Presencial => 'PRES',
            self::Mixta => 'EAD',
            self::EnLinea => 'ENL',
            self::VirtualTecnm => 'TNMV',
        };
    }
}
