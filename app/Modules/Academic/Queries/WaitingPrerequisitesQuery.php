<?php

namespace App\Modules\Academic\Queries;

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanPrerequisitePending;
use App\Modules\Academic\Models\StudyPlanSubject;

/**
 * Lecturas de los requisitos del SII que esperan (decisión del usuario del 10 de noviembre de 2026, confirmada) para
 * «Por validar»: al validar una materia, cuáles entran con ella a la seriación y cuáles esperan todavía a la otra
 * materia. Siempre con el mismo número de consultas, tenga los que tenga.
 */
final class WaitingPrerequisitesQuery
{
    /**
     * Los requisitos sin aplicar del plan que nombran a la materia `$subjectId`, como materia (`requires`: la materia
     * pide `code`) o como requisito (`code` la pide). `enters` son los que tienen la otra materia en la retícula (sin
     * quitar y sin borrar del catálogo): entran al asignarle semestre, salvo que cierren un ciclo (`note` dice por qué
     * uno siguió esperando). `waits` son los que esperan a que se valide la otra materia. Cada lista, primero lo que
     * pide y luego quién la pide, por clave.
     *
     * @return array{enters: list<array{id: string, code: string, requires: bool, kind: PrerequisiteKind, note: string|null}>, waits: list<array{id: string, code: string, requires: bool, kind: PrerequisiteKind, note: string|null}>}
     */
    public function forSubject(StudyPlan $plan, string $subjectId): array
    {
        $rows = StudyPlanPrerequisitePending::query()
            ->where('study_plan_id', $plan->getKey())
            ->whereNull('applied_at')
            ->where(fn ($query) => $query->where('subject_id', $subjectId)->orWhere('prerequisite_subject_id', $subjectId))
            ->with(['subject' => fn ($query) => $query->withTrashed(), 'prerequisite' => fn ($query) => $query->withTrashed()])
            ->get();

        $items = $rows->map(function (StudyPlanPrerequisitePending $row) use ($subjectId): array {
            $requires = $row->subject_id === $subjectId;
            $other = $requires ? $row->prerequisite : $row->subject;

            return ['id' => $row->id, 'other_id' => $other->id, 'code' => $other->code, 'requires' => $requires, 'kind' => $row->kind, 'note' => $row->note];
        })->sortBy([['requires', 'desc'], ['code', 'asc']])->values();

        $placed = $items->isEmpty() ? [] : array_flip(StudyPlanSubject::query()
            ->join('subjects', 'subjects.id', '=', 'study_plan_subjects.subject_id')
            ->whereNull('subjects.deleted_at')
            ->where('study_plan_subjects.study_plan_id', $plan->getKey())
            ->whereIn('study_plan_subjects.subject_id', $items->pluck('other_id')->all())
            ->pluck('study_plan_subjects.subject_id')
            ->all());

        [$enters, $waits] = $items->partition(fn (array $item): bool => isset($placed[$item['other_id']]));
        $shape = fn (array $item): array => array_diff_key($item, ['other_id' => true]);

        return ['enters' => $enters->map($shape)->values()->all(), 'waits' => $waits->map($shape)->values()->all()];
    }
}
