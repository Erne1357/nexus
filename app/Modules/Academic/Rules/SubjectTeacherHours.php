<?php

namespace App\Modules\Academic\Rules;

/**
 * PL-02 (tarea 1.73): las horas que una materia le cuenta al docente en su carga. Son las de la materia, teoría más
 * práctica, salvo que la materia no cuente para la carga del docente (`subjects.counts_teacher_hours` en falso, como las
 * tutorías): entonces 0. Es un dato de la materia, nunca una regla por su nombre o su clave. Las horas de sesión del
 * grupo (HC-12) y los créditos del alumno siguen siendo los de la materia. Puro.
 */
final class SubjectTeacherHours
{
    /** La misma cuenta en SQL, sobre la tabla `subjects`, para las sumas por docente. */
    public const string SQL = 'case when subjects.counts_teacher_hours then subjects.theory_hours + subjects.practice_hours else 0 end';

    public static function of(int $theoryHours, int $practiceHours, bool $countsTeacherHours): int
    {
        return $countsTeacherHours ? $theoryHours + $practiceHours : 0;
    }
}
