<?php

namespace App\Modules\Core\Enums;

/**
 * Forma de impartición de un campus (glosario). Cambia qué pasos del flujo aplican a través del
 * perfil de flujo; R1 y R2 implementan solo `presencial`.
 */
enum Modality: string
{
    case Presencial = 'presencial';
    case Mixta = 'mixta';
    case EnLinea = 'en_linea';
    case VirtualTecnm = 'virtual_tecnm';
}
