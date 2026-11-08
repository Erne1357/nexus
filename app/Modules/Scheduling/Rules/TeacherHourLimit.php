<?php

namespace App\Modules\Scheduling\Rules;

use App\Modules\Scheduling\Validation\ScheduleState;

/**
 * La cuenta de horas de un docente en el periodo que usa HC-08, para todos los que la necesitan (la regla, el ranking
 * y `teacher_term_loads` de Staffing): horas asignadas = teoría + práctica de la materia de cada grupo activo suyo, en
 * todos los campus (supuesto por confirmar); límite = horas base + `staffing.extra_hours_tolerance`, o
 * `staffing.honorarios_max_hours` si es de honorarios y el parámetro existe. `$exceptGroupId` no cuenta ese grupo:
 * así el ranking pregunta cuántas horas le quedarían a un candidato sin contarse a sí mismo.
 */
final class TeacherHourLimit
{
    /**
     * @return array{base_hours: int, external_hours: int, assigned_hours: int, tolerance: int, limit: int, remaining_hours: int, groups: array<string, int>}|null
     */
    public static function of(ScheduleState $state, string $teacherId, ?string $exceptGroupId = null): ?array
    {
        $teacher = $state->teacher($teacherId);

        if ($teacher === null) {
            return null;
        }

        $tolerance = (int) $state->setting('staffing.extra_hours_tolerance', 0);
        $cap = $state->setting('staffing.honorarios_max_hours');
        $groups = [];

        foreach ($state->groups() as $group) {
            if ($group->isActive && $group->teacherId === $teacherId && $group->id !== $exceptGroupId) {
                $groups[$group->id] = $state->subject($group->subjectId)?->weeklyHours() ?? 0;
            }
        }

        $assigned = array_sum($groups);
        $limit = $teacher->isHonorarios && $cap !== null ? (int) $cap : $teacher->baseHours + $tolerance;

        return [
            'base_hours' => $teacher->baseHours,
            'external_hours' => $teacher->externalHours,
            'assigned_hours' => $assigned,
            'tolerance' => $tolerance,
            'limit' => $limit,
            'remaining_hours' => $limit - $teacher->externalHours - $assigned,
            'groups' => $groups,
        ];
    }
}
