<?php

namespace App\Modules\Students\Queries;

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Enums\SubjectKind;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\SubjectPrerequisite;

/**
 * La retícula de un plan para calcular el avance de sus alumnos (PlanGrid): sus materias con sus créditos y su
 * semestre, por semestre y clave, su seriación, la especialidad de las materias de especialidad y los créditos
 * totales del plan. Deja fuera lo que se quitó de la retícula, las materias borradas del catálogo y los
 * correquisitos, que no impiden cursar una materia; una materia inactiva sigue, porque el plan se ata al alumno
 * al inscribirse.
 *
 * Servicio Social pide el 70 % de los créditos del plan y Residencia Profesional el 80 % y el servicio social
 * acreditado, que aquí se suma a su seriación (lineamientos del TecNM; reglas-de-negocio.md, "Elegibilidad y
 * demanda"). Se reconocen por su tipo (`subjects.kind`): mientras no se capture, no piden créditos.
 */
final class PlanGridQuery
{
    /** @var array<string, int> */
    private const array CREDIT_SHARE = [
        SubjectKind::Residencia->value => 80,
        SubjectKind::ServicioSocial->value => 70,
    ];

    public function forPlan(string $studyPlanId): PlanGrid
    {
        $placements = StudyPlanSubject::query()
            ->join('subjects', 'subjects.id', '=', 'study_plan_subjects.subject_id')
            ->whereNull('subjects.deleted_at')
            ->where('study_plan_subjects.study_plan_id', $studyPlanId)
            ->orderBy('study_plan_subjects.semester')
            ->orderBy('subjects.code')
            ->toBase()
            ->get(['study_plan_subjects.subject_id', 'study_plan_subjects.specialty_id', 'study_plan_subjects.semester', 'subjects.credits', 'subjects.kind']);

        $credits = $placements->mapWithKeys(fn (object $row): array => [$row->subject_id => (int) $row->credits])->all();
        $specialtyOf = $placements->whereNotNull('specialty_id')->pluck('specialty_id', 'subject_id')->all();
        $semesterOf = $placements->mapWithKeys(fn (object $row): array => [$row->subject_id => (int) $row->semester])->all();
        $creditShare = $placements->filter(fn (object $row): bool => $row->kind !== null && isset(self::CREDIT_SHARE[$row->kind]))
            ->mapWithKeys(fn (object $row): array => [$row->subject_id => self::CREDIT_SHARE[$row->kind]])
            ->all();

        $seriation = SubjectPrerequisite::query()
            ->where('study_plan_id', $studyPlanId)
            ->where('kind', PrerequisiteKind::Seriacion)
            ->orderBy('prerequisite_subject_id')
            ->get(['subject_id', 'prerequisite_subject_id'])
            ->groupBy('subject_id')
            ->map(fn ($rows): array => $rows->pluck('prerequisite_subject_id')->all())
            ->all();

        $services = $placements->where('kind', SubjectKind::ServicioSocial->value)->pluck('subject_id')->all();

        foreach ($placements->where('kind', SubjectKind::Residencia->value)->pluck('subject_id') as $residency) {
            $seriation[$residency] = array_values(array_unique([...($seriation[$residency] ?? []), ...$services]));
        }

        $totalCredits = StudyPlan::withTrashed()->whereKey($studyPlanId)->value('total_credits');

        return new PlanGrid($credits, $seriation, $specialtyOf, $semesterOf, $creditShare, $totalCredits === null ? null : (int) $totalCredits);
    }
}
