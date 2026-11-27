<?php

namespace App\Modules\Scheduling\Rules\Constraints;

use App\Modules\Scheduling\Rules\TeacherHourLimit;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;

/**
 * HC-22 (tarea 1.81, PL-04): también es el tope con honorarios de quien tiene plaza docente: las horas de su plaza más sus horas
 * extra (clases y externas por encima de frente a grupo + 2) no pueden pasar de `staffing.max_hours_with_honorarios` (por
 * omisión 44, confirmado el 27 de noviembre de 2026); mismo veto, misma marca `sin_cupo`.
 *
 * HC-22 (tarea 1.80, confirmado el 27 de noviembre de 2026): quien tiene plaza administrativa (apoyo y asistencia, PL-03)
 * no puede pasar de `staffing.administrative_max_hours` (por omisión 42) entre sus horas administrativas, sus clases
 * (las que le cuentan, PL-02) y sus horas externas (supuesto por confirmar). Es un choque duro: la asignación y la
 * elección en la selección lo vetan y el ranking no lo ofrece como asignable. La cuenta es TeacherHourLimit; HC-08 (la
 * banda de ±2) no le aplica. Se reporta una vez por grupo activo del docente que toca el alcance.
 */
final class AdministrativeHours implements Constraint
{
    public function rule(): string
    {
        return 'HC-22';
    }

    public function check(ScheduleState $state, ValidationScope $scope): iterable
    {
        foreach ($scope->teacherIds($state) as $teacherId) {
            $teacher = $state->teacher($teacherId);
            $hours = TeacherHourLimit::of($state, $teacherId);

            if ($teacher === null || $hours === null) {
                continue;
            }

            if ($hours['honorarios_excess'] > 0) {
                yield from $this->honorarios($scope, $teacher->name, $teacherId, $hours);

                continue;
            }

            if ($hours['administrative_hours'] === null || $hours['administrative_excess'] <= 0) {
                continue;
            }

            $message = $teacher->name.' '.lcfirst((string) TeacherHourLimit::administrativeNotice($hours));

            foreach ($hours['groups'] as $groupId => $groupHours) {
                if (! $scope->hasTeacher($teacherId) && ! $scope->hasGroup($groupId)) {
                    continue;
                }

                yield Violation::hard($this->rule(), $message, 'teacher', $teacherId, $groupId, [
                    'administrative_hours' => $hours['administrative_hours'],
                    'administrative_cap' => $hours['administrative_cap'],
                    'external_hours' => $hours['external_hours'],
                    'assigned_hours' => $hours['assigned_hours'],
                    'group_hours' => $groupHours,
                    'excess_hours' => $hours['administrative_excess'],
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $hours
     * @return iterable<Violation>
     */
    private function honorarios(ValidationScope $scope, string $name, string $teacherId, array $hours): iterable
    {
        $message = $name.' '.lcfirst((string) TeacherHourLimit::honorariosNotice($hours));

        foreach ($hours['groups'] as $groupId => $groupHours) {
            if (! $scope->hasTeacher($teacherId) && ! $scope->hasGroup($groupId)) {
                continue;
            }

            yield Violation::hard($this->rule(), $message, 'teacher', $teacherId, $groupId, [
                'plaza_hours' => $hours['plaza_hours'],
                'extra_hours' => $hours['extra_hours'],
                'with_honorarios_max' => $hours['with_honorarios_max'],
                'external_hours' => $hours['external_hours'],
                'assigned_hours' => $hours['assigned_hours'],
                'group_hours' => $groupHours,
                'excess_hours' => $hours['honorarios_excess'],
            ]);
        }
    }
}
