<?php

namespace App\Modules\Core\Enums;

/**
 * De dónde sale la ventana efectiva de una etapa: la general (`modality_id` nulo), la propia de la modalidad (que
 * reemplaza a la general; M4) o ninguna.
 */
enum StageSource: string
{
    case General = 'general';
    case Modality = 'modality';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::General => 'General',
            self::Modality => 'De la modalidad',
            self::None => 'Sin fecha',
        };
    }
}
