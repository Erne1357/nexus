<?php

namespace App\Modules\Communication\Enums;

/**
 * A quién son las fotos de un lote en ZIP (CO-S5): al personal, que se busca por `users.username`, o a los alumnos, que
 * se buscan por número de control.
 */
enum PhotoBatchKind: string
{
    case Personal = 'personal';
    case Alumnos = 'alumnos';

    public function label(): string
    {
        return match ($this) {
            self::Personal => 'Personal',
            self::Alumnos => 'Alumnos',
        };
    }
}
