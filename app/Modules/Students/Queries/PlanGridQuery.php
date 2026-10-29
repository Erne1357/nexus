<?php

namespace App\Modules\Students\Queries;

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\SubjectPrerequisite;

/**
 * La retícula de un plan para calcular el avance de sus alumnos (PlanGrid): sus materias con sus créditos, por
 * semestre y clave, su seriación y la especialidad de las materias de especialidad. Deja fuera lo que se quitó
 * de la retícula, las materias borradas del catálogo y los correquisitos, que no impiden cursar una materia;
 * una materia inactiva sigue, porque el plan se ata al alumno al inscribirse.
 */
final class PlanGridQuery
{
    public function forPlan(string $studyPlanId): PlanGrid
    {
        $placements = StudyPlanSubject::query()
            ->join('subjects', 'subjects.id', '=', 'study_plan_subjects.subject_id')
            ->whereNull('subjects.deleted_at')
            ->where('study_plan_subjects.study_plan_id', $studyPlanId)
            ->orderBy('study_plan_subjects.semester')
            ->orderBy('subjects.code')
            ->toBase()
            ->get(['study_plan_subjects.subject_id', 'study_plan_subjects.specialty_id', 'subjects.credits']);

        $credits = $placements->mapWithKeys(fn (object $row): array => [$row->subject_id => (int) $row->credits])->all();
        $specialtyOf = $placements->whereNotNull('specialty_id')->pluck('specialty_id', 'subject_id')->all();

        $seriation = SubjectPrerequisite::query()
            ->where('study_plan_id', $studyPlanId)
            ->where('kind', PrerequisiteKind::Seriacion)
            ->orderBy('prerequisite_subject_id')
            ->get(['subject_id', 'prerequisite_subject_id'])
            ->groupBy('subject_id')
            ->map(fn ($rows): array => $rows->pluck('prerequisite_subject_id')->all())
            ->all();

        return new PlanGrid($credits, $seriation, $specialtyOf);
    }
}
