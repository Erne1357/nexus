<?php

namespace App\Modules\Scheduling\Rules;

use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\State\StateGroup;

/**
 * Las cohortes de un grupo para el motor de horarios (docs/arquitectura/solver.md, «Cohortes»): conjuntos de grupos que
 * un mismo alumno toma juntos y que no se empalman. Salen de HC-03, el alumno tipo, con la misma decisión de
 * TypicalStudentSchedule de quién cuenta: un grupo activo que es el único grupo activo de su materia en su plan,
 * semestre reticular, campus y modalidad (donde la materia tiene otro grupo hay combinación que elegir, y el alumno
 * tipo no queda atado a uno). Su clave es `plan:<study_plan_id>:<semestre>:<campus_code>`. Además, cada grupo activo
 * con paquete (DM-09) lleva `package:<package_id>:<número>` (un grupo puede llevar las dos). Puro: solo lee la foto.
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
            $keys = [];

            if (self::isSoleGroupOfSubject($state, $group)) {
                $keys[] = "plan:{$group->studyPlanId}:{$group->semester}:{$state->campusCode($group->campusId)}";
            }

            if ($group->isActive && $group->packageId !== null && $group->packageNumber !== null) {
                $keys[] = self::packageKey($group);
            }

            $cohorts[$group->id] = $keys;
        }

        return $cohorts;
    }

    /**
     * `package:<package_id>:<número>` (DM-09): la instancia n de un paquete; sus grupos toman los mismos alumnos de
     * nuevo ingreso. El número se cuenta por campus, así que quien agrupa por esta clave también separa por campus.
     */
    public static function packageKey(StateGroup $group): string
    {
        return "package:{$group->packageId}:{$group->packageNumber}";
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
