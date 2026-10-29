<?php

namespace App\Modules\Students\Queries;

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\SubjectPrerequisite;
use App\Modules\Students\Progress\PlanGrid;

/**
 * La retícula de un plan para calcular el avance de sus alumnos (PlanGrid): sus materias con sus créditos, por
 * semestre y clave, y su seriación. Deja fuera lo que se quitó de la retícula, las materias borradas del
 * catálogo y los correquisitos, que no impiden cursar una materia.
 */
final class PlanGridQuery
{
    public function forPlan(string $studyPlanId): PlanGrid
    {
        $credits = StudyPlanSubject::query()
            ->join('subjects', 'subjects.id', '=', 'study_plan_subjects.subject_id')
            ->whereNull('subjects.deleted_at')
            ->where('study_plan_subjects.study_plan_id', $studyPlanId)
            ->orderBy('study_plan_subjects.semester')
            ->orderBy('subjects.code')
            ->pluck('subjects.credits', 'study_plan_subjects.subject_id')
            ->map(fn (mixed $credits): int => (int) $credits)
            ->all();

        $seriation = SubjectPrerequisite::query()
            ->where('study_plan_id', $studyPlanId)
            ->where('kind', PrerequisiteKind::Seriacion)
            ->orderBy('prerequisite_subject_id')
            ->get(['subject_id', 'prerequisite_subject_id'])
            ->groupBy('subject_id')
            ->map(fn ($rows): array => $rows->pluck('prerequisite_subject_id')->all())
            ->all();

        return new PlanGrid($credits, $seriation);
    }
}
