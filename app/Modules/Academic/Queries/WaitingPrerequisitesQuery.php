<?php

namespace App\Modules\Academic\Queries;

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanPrerequisitePending;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Rules\SeriationRules;

/**
 * Lecturas de los requisitos del SII que esperan (decisión del usuario del 10 de noviembre de 2026, confirmada) para
 * «Por validar»: al validar una materia, cuáles entran con ella a la seriación, cuáles no entrarían porque cerrarían un
 * ciclo y cuáles esperan todavía a la otra materia. Siempre con el mismo número de consultas, tenga los que tenga.
 */
final class WaitingPrerequisitesQuery
{
    /**
     * Los requisitos sin aplicar del plan que nombran a la materia `$subjectId`, como materia (`requires`: la materia
     * pide `code`) o como requisito (`code` la pide), con su nota si la tienen.
     *
     * `enters` son los que tienen la otra materia en la retícula (sin quitar y sin borrar del catálogo): entran al
     * asignarle semestre. `cycle` marca los que no entrarían porque cerrarían un ciclo de seriación, con el mismo orden
     * que ApplyWaitingPrerequisites: primero los que respetan el orden de los semestres, con `$semester` como el de la
     * materia (el elegido en el panel), y luego por orden de alta. `waits` son los que esperan a que se valide la otra
     * materia. `notes`, los de las dos listas que tienen nota. Cada lista, primero lo que pide y luego quién la pide, por
     * clave.
     *
     * @return array{enters: list<array{id: string, code: string, requires: bool, kind: PrerequisiteKind, note: string|null, cycle: bool}>, waits: list<array{id: string, code: string, requires: bool, kind: PrerequisiteKind, note: string|null, cycle: bool}>, notes: list<array{id: string, code: string, requires: bool, kind: PrerequisiteKind, note: string|null, cycle: bool}>}
     */
    public function forSubject(StudyPlan $plan, string $subjectId, ?int $semester = null): array
    {
        $rows = StudyPlanPrerequisitePending::query()
            ->where('study_plan_id', $plan->getKey())
            ->whereNull('applied_at')
            ->where(fn ($query) => $query->where('subject_id', $subjectId)->orWhere('prerequisite_subject_id', $subjectId))
            ->with(['subject' => fn ($query) => $query->withTrashed(), 'prerequisite' => fn ($query) => $query->withTrashed()])
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return ['enters' => [], 'waits' => [], 'notes' => []];
        }

        $other = fn (StudyPlanPrerequisitePending $row): string => $row->subject_id === $subjectId ? $row->prerequisite_subject_id : $row->subject_id;
        $semesters = StudyPlanSubject::query()
            ->join('subjects', 'subjects.id', '=', 'study_plan_subjects.subject_id')
            ->whereNull('subjects.deleted_at')
            ->where('study_plan_subjects.study_plan_id', $plan->getKey())
            ->whereIn('study_plan_subjects.subject_id', $rows->map($other)->all())
            ->pluck('study_plan_subjects.semester', 'study_plan_subjects.subject_id')
            ->map(fn (mixed $value): int => (int) $value)
            ->all();

        [$entering, $waiting] = $rows->partition(fn (StudyPlanPrerequisitePending $row): bool => isset($semesters[$other($row)]));
        $cycles = $this->cycles($plan, $entering->all(), [...$semesters, $subjectId => $semester]);

        $item = fn (StudyPlanPrerequisitePending $row): array => [
            'id' => $row->id,
            'code' => ($row->subject_id === $subjectId ? $row->prerequisite : $row->subject)->code,
            'requires' => $row->subject_id === $subjectId,
            'kind' => $row->kind,
            'note' => $row->note,
            'cycle' => isset($cycles[$row->id]),
        ];
        $sorted = fn (iterable $rows): array => collect($rows)->map($item)->sortBy([['requires', 'desc'], ['code', 'asc']])->values()->all();

        $enters = $sorted($entering);
        $waits = $sorted($waiting);

        return ['enters' => $enters, 'waits' => $waits, 'notes' => array_values(array_filter([...$enters, ...$waits], fn (array $item): bool => $item['note'] !== null))];
    }

    /**
     * Los que no entrarían porque cerrarían un ciclo con la seriación del plan o con uno que entra antes, por id.
     *
     * @param  list<StudyPlanPrerequisitePending>  $rows  en orden de alta
     * @param  array<string, int|null>  $semesters
     * @return array<string, true>
     */
    private function cycles(StudyPlan $plan, array $rows, array $semesters): array
    {
        $seriations = array_filter($rows, fn (StudyPlanPrerequisitePending $row): bool => $row->kind === PrerequisiteKind::Seriacion);

        if ($seriations === []) {
            return [];
        }

        usort($seriations, fn (StudyPlanPrerequisitePending $a, StudyPlanPrerequisitePending $b): int => [
            SeriationRules::goesBackwards($a->kind, $semesters[$a->subject_id] ?? null, $semesters[$a->prerequisite_subject_id] ?? null), $a->id,
        ] <=> [
            SeriationRules::goesBackwards($b->kind, $semesters[$b->subject_id] ?? null, $semesters[$b->prerequisite_subject_id] ?? null), $b->id,
        ]);

        $edges = SeriationRules::edgesFor($plan->getKey());
        $cycles = [];

        foreach ($seriations as $row) {
            if (SeriationRules::closesCycle($edges, $row->subject_id, $row->prerequisite_subject_id)) {
                $cycles[$row->id] = true;
            } else {
                $edges[$row->subject_id][$row->prerequisite_subject_id] = true;
            }
        }

        return $cycles;
    }
}
