<?php

namespace App\Modules\Academic\Enums;

/**
 * Cómo se relaciona una materia con su requisito en un plan (`subject_prerequisites.kind`): seriación
 * (aprobarla antes) o correquisito.
 */
enum PrerequisiteKind: string
{
    case Seriacion = 'seriacion';
    case Correquisito = 'correquisito';

    public function label(): string
    {
        return match ($this) {
            self::Seriacion => 'Seriación',
            self::Correquisito => 'Correquisito',
        };
    }
}
