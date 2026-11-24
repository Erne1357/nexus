<?php

namespace App\Modules\Faculty\Queries;

use App\Modules\Faculty\Models\Teacher;

/**
 * Sin el módulo de carga docente no hay grupos que mostrar: la pestaña «Carga» sale vacía.
 */
final class NoTeacherWeeklyLoad implements TeacherWeeklyLoad
{
    public function forTeacher(Teacher $teacher): array
    {
        return ['current' => null, 'next' => null, 'upcoming' => null, 'base_hours' => 0];
    }
}
