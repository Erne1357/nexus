<?php

namespace App\Modules\Scheduling\Rules\Constraints;

use App\Modules\Scheduling\Rules\TeacherHourLimit;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;

/**
 * HC-08: las horas asignadas a un docente en el periodo (todos los campus) más sus horas externas, contra su banda de
 * horas frente a grupo (PL-01) más 2 (art. 147) y la tolerancia configurable (`staffing.extra_hours_tolerance`, por
 * defecto 0); de honorarios, el tope configurable (`staffing.honorarios_max_hours`). La cuenta es TeacherHourLimit.
 *
 * Desde la tarea 1.50 (confirmado el 26 de noviembre de 2026: «Quiero que deje de ser un tope duro, sí se puede pero
 * tienen que firmar un oficio») es un **aviso** en todo lo que deciden personas: pasarse se permite y el mensaje dice
 * que falta el oficio de conformidad del docente (pendiente) o, si la casilla de SEL-12 ya cubre sus horas, que el
 * docente lo aceptó. En la foto del motor (ContractState, `engineLimit`) sigue siendo choque duro contra el límite de
 * la entrada (supuesto por confirmar).
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

            if ($teacher === null || $hours === null || ! $hours['over_band']) {
                continue;
            }

            $assigned = $hours['assigned_hours'];
            $remaining = $hours['remaining_hours'];
            $missing = max(0, -$remaining);
            $total = $assigned + $teacher->externalHours;
            $message = $hours['hard']
                ? "{$teacher->name} suma {$total} h y su límite es {$hours['limit']} h: se pasa por {$missing} h."
                : $teacher->name.' '.lcfirst((string) TeacherHourLimit::notice($hours));

            foreach ($hours['groups'] as $groupId => $groupHours) {
                if (! $scope->hasTeacher($teacherId) && ! $scope->hasGroup($groupId)) {
                    continue;
                }

                $related = [
                    'base_hours' => $teacher->baseHours,
                    'teaching_hours' => $hours['teaching_hours'],
                    'band_from' => $hours['band_from'],
                    'band_to' => $hours['band_to'],
                    'external_hours' => $teacher->externalHours,
                    'assigned_hours' => $assigned,
                    'assigned_without_group' => $assigned - $groupHours,
                    'group_hours' => $groupHours,
                    'tolerance' => $hours['tolerance'],
                    'band_limit' => $hours['band_limit'],
                    'limit' => $hours['limit'],
                    'remaining_hours' => $remaining,
                    'missing_hours' => $missing,
                    'accepted' => $hours['accepted'],
                ];

                yield $hours['hard']
                    ? Violation::hard($this->rule(), $message, 'teacher', $teacherId, $groupId, $related)
                    : Violation::soft($this->rule(), $message, 'teacher', $teacherId, $groupId, $related);
            }
        }
    }
}
