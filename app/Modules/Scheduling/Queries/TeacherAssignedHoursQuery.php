<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Core\Models\Term;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;

/**
 * Horas asignadas por docente en un periodo, en una sola consulta agregada: la suma de teoría más práctica de la
 * materia de cada grupo no cancelado con docente, en cualquier campus (supuesto por confirmar: las horas de un grupo
 * son las de su materia). Es la única definición de «horas asignadas» y la comparten HC-08 (TeacherHours) y las
 * `teacher_term_loads` de Staffing.
 */
final class TeacherAssignedHoursQuery
{
    /**
     * @param  list<string>|null  $teacherIds  Solo estos docentes; null, todos los que tienen grupos.
     * @return array<string, int> Horas por id de docente; los docentes sin grupos no aparecen.
     */
    public function forTerm(Term $term, ?array $teacherIds = null): array
    {
        return Group::query()
            ->join('subjects', 'subjects.id', '=', 'groups.subject_id')
            ->where('groups.term_id', $term->id)
            ->whereNotNull('groups.teacher_id')
            ->where('groups.status', '<>', GroupStatus::Cancelled->value)
            ->when($teacherIds !== null, fn ($query) => $query->whereIn('groups.teacher_id', $teacherIds))
            ->groupBy('groups.teacher_id')
            ->selectRaw('groups.teacher_id, sum(subjects.theory_hours + subjects.practice_hours) as hours')
            ->orderBy('groups.teacher_id')
            ->pluck('hours', 'teacher_id')
            ->map(fn ($hours): int => (int) $hours)
            ->all();
    }
}
