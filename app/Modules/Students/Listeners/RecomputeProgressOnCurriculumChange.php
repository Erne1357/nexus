<?php

namespace App\Modules\Students\Listeners;

use App\Modules\Academic\Events\CurriculumChanged;
use App\Modules\Students\Jobs\RecomputeStudentProgress;

/**
 * Cuando cambia algo de la retícula o de una materia que entra en el avance (CurriculumChanged, ya
 * confirmado), encola el recálculo de `student_progress` de todos los alumnos. El job es único en la
 * cola, así que varios cambios seguidos esperan un solo recálculo; el plan o la materia del evento no
 * acotan nada porque RefreshStudentProgress siempre recalcula completo.
 */
final class RecomputeProgressOnCurriculumChange
{
    public function handle(CurriculumChanged $event): void
    {
        RecomputeStudentProgress::dispatch();
    }
}
