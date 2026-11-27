<?php

namespace App\Modules\Staffing\Rules;

/**
 * SEL-06. Escalón de un docente en el orden de selección: la plaza (horas exactas del CSV) da el escalón base
 * (40 → 0, 30 → 1, 20 → 2, cualquier otro valor → 3, asignatura) y cada criterio fallido (de 4) baja un escalón,
 * sin piso. Menor escalón elige antes. Puro.
 */
final readonly class SelectionTier
{
    public static function plazaTier(int $hours): int
    {
        return match ($hours) {
            40 => 0,
            30 => 1,
            20 => 2,
            default => 3,
        };
    }

    public static function tier(int $hours, int $failed): int
    {
        return self::plazaTier($hours) + $failed;
    }
}
