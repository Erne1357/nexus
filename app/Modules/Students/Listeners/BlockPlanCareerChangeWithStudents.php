<?php

namespace App\Modules\Students\Listeners;

use App\Modules\Academic\Events\PlanCareerChanging;
use App\Modules\Students\Models\Student;

/**
 * Veta cambiar la carrera de un plan que tiene alumnos (reglas-de-negocio.md, plan → carrera): la
 * llave compuesta (study_plan_id, career_id) de `students` movería la carrera de cada uno. Cuenta
 * los alumnos no borrados con ese plan, cualquiera sea su estatus: «Tiene 1 alumno inscrito en este
 * plan.» o «Tiene N alumnos inscritos en este plan.». Corre en la misma petición, dentro de la
 * transacción de UpdateStudyPlan.
 */
final class BlockPlanCareerChangeWithStudents
{
    public function handle(PlanCareerChanging $event): void
    {
        $total = Student::query()->where('study_plan_id', $event->studyPlan->id)->count();

        if ($total === 0) {
            return;
        }

        $event->block($total === 1
            ? 'Tiene 1 alumno inscrito en este plan.'
            : "Tiene {$total} alumnos inscritos en este plan.");
    }
}
