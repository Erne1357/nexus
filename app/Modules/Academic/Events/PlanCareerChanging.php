<?php

namespace App\Modules\Academic\Events;

use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Events\Concerns\CanBeVetoed;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se va a cambiar la carrera de un plan (UpdateStudyPlan, `career_id` distinto). Evento con veto: se
 * despacha dentro de la transacción, antes de guardar, y un módulo de la derecha lo bloquea con
 * block(). Students lo bloquea si algún alumno tiene el plan, porque la llave compuesta
 * (study_plan_id, career_id) de `students` movería su carrera. `$studyPlan` ya trae la carrera nueva
 * sin guardar; `$fromCareerId` es la actual. Si alguien bloquea, UpdateStudyPlan rechaza con sus
 * motivos en `career_id`.
 */
final class PlanCareerChanging
{
    use CanBeVetoed, Dispatchable;

    public function __construct(
        public StudyPlan $studyPlan,
        public string $fromCareerId,
    ) {}
}
