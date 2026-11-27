<?php

namespace App\Modules\Faculty\Enums;

/**
 * `teacher_subject_qualifications.source`: quién puso la materia en la experiencia académica. `manual` es la que declara un jefe;
 * `history` la deriva DeriveQualificationsFromHistory del historial reciente (D-02).
 */
enum QualificationSource: string
{
    case Manual = 'manual';
    case History = 'history';
}
