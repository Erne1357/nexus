<?php

namespace App\Modules\Core\Enums;

/**
 * De dónde sale la ventana efectiva de una etapa: la general (`campus_id` nulo), la propia del campus
 * (que reemplaza a la general) o ninguna.
 */
enum StageSource: string
{
    case General = 'general';
    case Campus = 'campus';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::General => 'General',
            self::Campus => 'Del campus',
            self::None => 'Sin fecha',
        };
    }
}
