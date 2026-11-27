<?php

namespace App\Modules\Students\Queries;

use App\Modules\Academic\Contracts\ActiveStudyPlans;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Models\Student;

/**
 * Los planes de estudio con al menos un alumno activo (SEL-19; confirmado el 27 de noviembre de 2026). Baja temporal,
 * baja definitiva y egresado no cuentan (la baja temporal es supuesto por confirmar). Es la lectura que Academic pide por
 * su contrato ActiveStudyPlans.
 */
final class ActiveStudyPlansQuery implements ActiveStudyPlans
{
    public function ids(): array
    {
        return Student::query()
            ->where('status', StudentStatus::Activo->value)
            ->whereNotNull('study_plan_id')
            ->distinct()
            ->pluck('study_plan_id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
    }
}
