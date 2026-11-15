<?php

namespace App\Modules\Students\Listeners;

use App\Modules\Academic\Events\CurriculumChanged;
use App\Modules\Students\Jobs\RecomputeStudentProgress;

/**
 * Cuando cambia algo de la retícula o de una materia que entra en el avance (CurriculumChanged, ya
 * confirmado), encola el recálculo de `student_progress`. Si el evento trae el plan, solo se recalculan los alumnos de
 * ese plan, con un job único por plan; sin plan (cambió una materia, que puede estar en cualquier plan) se recalculan
 * todos, como siempre. Varios cambios seguidos del mismo alcance esperan un solo recálculo.
 */
final class RecomputeProgressOnCurriculumChange
{
    public function handle(CurriculumChanged $event): void
    {
        RecomputeStudentProgress::dispatch($event->studyPlanId);
    }
}
