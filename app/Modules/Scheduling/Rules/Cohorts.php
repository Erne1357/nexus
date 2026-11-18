<?php

namespace App\Modules\Scheduling\Rules;

use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\State\StateGroup;

/**
 * Las cohortes de un grupo para el motor de horarios (docs/arquitectura/solver.md, «Cohortes»): conjuntos de grupos que
 * un mismo alumno toma juntos y que no se empalman. Hoy solo salen de HC-03, el alumno tipo, con la misma decisión de
 * TypicalStudentSchedule de quién cuenta: un grupo activo que es el único grupo activo de su materia en su plan,
 * semestre reticular, campus y modalidad (donde la materia tiene otro grupo hay combinación que elegir, y el alumno
 * tipo no queda atado a uno). Su clave es `plan:<study_plan_id>:<semestre>:<campus_code>`. Las cohortes de los
 * paquetes de primer semestre (`package:<id>:<n>`, DM-09) se agregarán aquí, leyendo los grupos del mismo
 * ScheduleState. Puro: solo lee la foto.
 */
final class Cohorts
{
    /**
     * Las claves de cohorte de cada grupo dado, por id de grupo: lista vacía si no lleva ninguna.
     *
     * @param  iterable<StateGroup>  $groups
     * @return array<string, list<string>>
     */
    public static function of(ScheduleState $state, iterable $groups): array
    {
        $cohorts = [];

        foreach ($groups as $group) {
            $cohorts[$group->id] = self::isSoleGroupOfSubject($state, $group)
                ? ["plan:{$group->studyPlanId}:{$group->semester}:{$state->campusCode($group->campusId)}"]
                : [];
        }

        return $cohorts;
    }

    private static function isSoleGroupOfSubject(ScheduleState $state, StateGroup $group): bool
    {
        if (! $group->isActive) {
            return false;
        }

        $sameSubject = array_filter(
            $state->groupsOfSemester($group->modalityId, $group->campusId, $group->studyPlanId, $group->semester),
            fn (StateGroup $other): bool => $other->subjectId === $group->subjectId,
        );

        return count($sameSubject) === 1;
    }
}
