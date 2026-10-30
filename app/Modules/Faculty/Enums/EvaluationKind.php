<?php

namespace App\Modules\Faculty\Enums;

/**
 * `teacher_evaluations.kind`: evaluación de los estudiantes o del departamento.
 */
enum EvaluationKind: string
{
    case Estudiantil = 'estudiantil';
    case Departamental = 'departamental';

    public function label(): string
    {
        return match ($this) {
            self::Estudiantil => 'Estudiantil',
            self::Departamental => 'Departamental',
        };
    }
}
