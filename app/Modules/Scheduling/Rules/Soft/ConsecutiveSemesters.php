<?php

namespace App\Modules\Scheduling\Rules\Soft;

use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\State\StateGroup;
use App\Modules\Scheduling\Validation\ValidationScope;

/**
 * SC-07: las materias de semestres consecutivos de una carrera no se empalman entre sí, para los alumnos irregulares.
 * La carrera es el plan de estudios del grupo, y como el alumno tipo, va por campus y modalidad.
 *
 * Unidad (supuesto por confirmar): una hora de empalme entre dos grupos activos del mismo plan, campus y modalidad, de
 * semestres reticulares seguidos (N y N+1); una fracción cuenta como hora. Cada par se cuenta una vez si alguno de los
 * dos es del alcance. Lee el plan y el semestre de cada grupo, no los semestres del alumno tipo de la foto.
 */
final class ConsecutiveSemesters implements SoftRule
{
    public function rule(): string
    {
        return 'SC-07';
    }

    public function penalty(ScheduleState $state, ValidationScope $scope, SoftContext $context): int
    {
        $bySemester = [];

        foreach ($state->groups() as $group) {
            if ($group->isActive) {
                $bySemester[self::key($group, $group->semester)][] = $group;
            }
        }

        $seen = [];
        $hours = 0;

        foreach ($scope->groupIds() as $groupId) {
            $group = $state->group($groupId);

            if ($group === null || ! $group->isActive) {
                continue;
            }

            foreach ([$group->semester - 1, $group->semester + 1] as $semester) {
                foreach ($bySemester[self::key($group, $semester)] ?? [] as $other) {
                    $pair = $group->id < $other->id ? "{$group->id}|{$other->id}" : "{$other->id}|{$group->id}";

                    if (isset($seen[$pair])) {
                        continue;
                    }

                    $seen[$pair] = true;
                    $minutes = 0;

                    foreach ($state->sessionsOf($group->id) as $mine) {
                        foreach ($state->sessionsOf($other->id) as $theirs) {
                            $minutes += Timeline::overlap($mine, $theirs);
                        }
                    }

                    $hours += Timeline::hours($minutes);
                }
            }
        }

        return $hours;
    }

    private static function key(StateGroup $group, int $semester): string
    {
        return "{$group->modalityId}|{$group->campusId}|{$group->studyPlanId}|{$semester}";
    }
}
