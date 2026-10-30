<?php

namespace App\Modules\Faculty\Enums;

/**
 * `teacher_subject_qualifications.level`: nivel del perfil que el jefe declara para una materia.
 */
enum QualificationLevel: string
{
    case Apto = 'apto';
    case Preferente = 'preferente';

    public function label(): string
    {
        return match ($this) {
            self::Apto => 'Apto',
            self::Preferente => 'Preferente',
        };
    }
}
