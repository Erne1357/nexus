<?php

namespace App\Modules\Scheduling\Rules\Constraints;

use App\Modules\Scheduling\Rules\TeacherHourLimit;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;

/**
 * HC-08: las horas asignadas a un docente en el periodo (todos los campus) más sus horas externas no pueden exceder
 * sus horas base más la tolerancia configurable (`staffing.extra_hours_tolerance`, por defecto 0). Honorarios: tope
 * configurable (`staffing.honorarios_max_hours`).
 *
 * Las horas de un grupo son las de su materia, teoría más práctica (supuesto por confirmar); la misma definición que
 * TeacherAssignedHoursQuery. Se reporta una vez por grupo activo del docente que toca el alcance.
 */
final class TeacherHours implements Constraint
{
    public function rule(): string
    {
        return 'HC-08';
    }

    public function check(ScheduleState $state, ValidationScope $scope): iterable
    {
        foreach ($scope->teacherIds($state) as $teacherId) {
            $teacher = $state->teacher($teacherId);
            $hours = TeacherHourLimit::of($state, $teacherId);

            if ($teacher === null || $hours === null || $hours['remaining_hours'] >= 0) {
                continue;
            }

            $hoursByGroup = $hours['groups'];
            $assigned = $hours['assigned_hours'];
            $limit = $hours['limit'];
            $tolerance = $hours['tolerance'];

            $remaining = $limit - $teacher->externalHours - $assigned;
            $missing = max(0, -$remaining);
            $total = $assigned + $teacher->externalHours;

            foreach ($hoursByGroup as $groupId => $groupHours) {
                if (! $scope->hasTeacher($teacherId) && ! $scope->hasGroup($groupId)) {
                    continue;
                }

                yield Violation::hard(
                    $this->rule(),
                    "{$teacher->name} pasaría a {$total} h de {$limit} (faltan {$missing} h).",
                    'teacher',
                    $teacherId,
                    $groupId,
                    [
                        'base_hours' => $teacher->baseHours,
                        'external_hours' => $teacher->externalHours,
                        'assigned_hours' => $assigned,
                        'assigned_without_group' => $assigned - $groupHours,
                        'group_hours' => $groupHours,
                        'tolerance' => $tolerance,
                        'limit' => $limit,
                        'remaining_hours' => $remaining,
                        'missing_hours' => $missing,
                    ],
                );
            }
        }
    }
}
