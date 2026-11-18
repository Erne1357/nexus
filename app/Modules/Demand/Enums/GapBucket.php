<?php

namespace App\Modules\Demand\Enums;

/**
 * Qué tan lejos está una materia del alumno elegible para ella (reglas-de-negocio.md, «Elegibilidad y demanda», DM-03;
 * confirmado el 17 de noviembre de 2026): el semestre de la materia en la retícula de su plan menos el semestre
 * estimado del alumno (StandingCalculator), en cuatro tramos. La no inscripción se mide por materia, campus y tramo,
 * porque un alumno toma mucho más lo de su semestre o lo atrasado que lo que le queda adelante en su alcance.
 */
enum GapBucket: string
{
    /** La materia es de un semestre anterior al del alumno (−1 o menos). */
    case Behind = 'behind';

    /** La materia es de su semestre. */
    case Same = 'same';

    /** La materia es del semestre siguiente. */
    case OneAhead = 'one_ahead';

    /** La materia está dos semestres adelante o más (el alcance llega a dos). */
    case TwoAhead = 'two_ahead';

    public static function of(int $subjectSemester, int $studentSemester): self
    {
        $gap = $subjectSemester - $studentSemester;

        return match (true) {
            $gap <= -1 => self::Behind,
            $gap === 0 => self::Same,
            $gap === 1 => self::OneAhead,
            default => self::TwoAhead,
        };
    }
}
