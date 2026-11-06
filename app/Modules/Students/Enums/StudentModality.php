<?php

namespace App\Modules\Students\Enums;

/**
 * Modalidad en que estudia un alumno (`students.modality`): la carrera y la retícula son una sola para todo el Tec y
 * la modalidad es del alumno (D15, confirmado el 5 de noviembre de 2026).
 */
enum StudentModality: string
{
    case Presencial = 'presencial';
    case Distancia = 'distancia';
    case Mixta = 'mixta';

    public function label(): string
    {
        return match ($this) {
            self::Presencial => 'Presencial',
            self::Distancia => 'A distancia',
            self::Mixta => 'Mixta',
        };
    }
}
