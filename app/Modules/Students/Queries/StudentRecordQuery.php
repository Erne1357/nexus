<?php

namespace App\Modules\Students\Queries;

use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\Subject;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Enums\SubjectStanding;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * La ficha de un alumno (tarea 1.6): su retícula por semestre con el estado de cada materia como lo nombra el
 * SII (SubjectStanding), las cadenas de seriación, sus cifras y su kárdex por periodo. El avance se calcula al
 * leer con PlanGrid, desde su kardex y su semestre, para que la ficha no espere al siguiente recálculo de
 * `student_progress` (reglas-de-negocio.md, "Retícula del alumno"). Una vez elegida la especialidad, no muestra
 * las materias de las otras.
 */
final class StudentRecordQuery
{
    public function __construct(
        private readonly PlanGridQuery $grids,
        private readonly TermInProgressQuery $terms,
    ) {}

    public function forStudent(Student $student): StudentRecord
    {
        $student->loadMissing([
            'career' => fn (BelongsTo $career) => $career->withTrashed(),
            'studyPlan' => fn (BelongsTo $plan) => $plan->withTrashed(),
            'campus' => fn (BelongsTo $campus) => $campus->withTrashed(),
        ]);
        $grid = $this->grids->forPlan($student->study_plan_id);
        $records = $student->kardexRecords()->with(['subject' => fn (BelongsTo $subject) => $subject->withTrashed()])->get();
        $approved = $records->where('result', KardexResult::Aprobada)->pluck('subject_id')->unique()->values()->all();
        $taking = $records->where('result', KardexResult::Cursando)->pluck('subject_id')->unique()->values()->all();
        $progress = $grid->progressFor($approved, $taking, $student->current_semester);

        $shown = array_keys(array_intersect_key($grid->credits, array_flip([...$approved, ...$progress['subjects_pending']])));
        $available = array_flip($progress['subjects_available']);
        $subjects = Subject::withTrashed()->whereIn('id', array_keys($grid->credits))->get(['id', 'code', 'name'])->keyBy('id');
        $specialties = Specialty::withTrashed()->whereIn('id', array_values($grid->specialtyOf))->pluck('name', 'id');
        [$chainOf, $chains] = $this->chains($shown, $grid->seriation, $subjects);
        $termInProgress = $this->terms->forCampus($student->campus_id);
        $reach = $student->current_semester === null ? null : $student->current_semester + 2;
        $bySubject = $records->groupBy('subject_id');
        $passed = array_flip($approved);

        $semesters = [];

        foreach ($shown as $subjectId) {
            $rows = $bySubject->get($subjectId, collect());
            $standing = SubjectStanding::of(
                $rows->map(fn (KardexRecord $row): array => ['result' => $row->result, 'attempt' => $row->attempt, 'term' => $row->term_code])->values()->all(),
                isset($available[$subjectId]),
                $termInProgress,
            );
            $shownRow = $this->shownRow($rows, $standing);
            $prerequisites = array_values(array_filter($grid->seriation[$subjectId] ?? [], fn (string $id): bool => isset($grid->credits[$id])));
            $semester = $grid->semesterOf[$subjectId];

            $semesters[$semester][] = new GridCell(
                subjectId: $subjectId,
                code: $subjects[$subjectId]->code,
                name: $subjects[$subjectId]->name,
                credits: $grid->credits[$subjectId],
                semester: $semester,
                specialty: isset($grid->specialtyOf[$subjectId]) ? $specialties[$grid->specialtyOf[$subjectId]] : null,
                standing: $standing,
                grade: $shownRow?->grade,
                termCode: $shownRow?->term_code,
                attempt: $shownRow?->attempt,
                prerequisites: $this->codes($prerequisites, $subjects),
                missing: $this->codes(array_filter($prerequisites, fn (string $id): bool => ! isset($passed[$id])), $subjects),
                creditShare: $grid->creditShare[$subjectId] ?? null,
                beyondReach: $reach !== null && $semester > $reach,
                chain: $chainOf[$subjectId] ?? null,
            );
        }

        ksort($semesters);
        $cells = array_merge(...array_values($semesters));

        return new StudentRecord(
            student: $student,
            creditsApproved: $progress['credits_approved'],
            totalCredits: $grid->totalFor([...$approved, ...$taking]),
            subjectsApproved: $progress['subjects_approved'],
            subjectsInGrid: count($shown),
            taking: count(array_filter($cells, fn (GridCell $cell): bool => $cell->standing === SubjectStanding::Cursando)),
            selectable: count(array_filter($cells, fn (GridCell $cell): bool => $cell->standing->countsAsSelectable() && isset($available[$cell->subjectId]))),
            reachSemester: $reach,
            semesters: $semesters,
            chains: $chains,
            kardex: $this->kardex($records),
        );
    }

    /**
     * La fila de kardex que explica el estado de la materia: la que la acreditó, la que la cursa o la última
     * oportunidad que reprobó.
     *
     * @param  Collection<int, KardexRecord>  $rows
     */
    private function shownRow(Collection $rows, SubjectStanding $standing): ?KardexRecord
    {
        $result = match ($standing) {
            SubjectStanding::Acreditada => KardexResult::Aprobada,
            SubjectStanding::Cursando, SubjectStanding::SinCalificar => KardexResult::Cursando,
            SubjectStanding::ReprobadaPrioridad, SubjectStanding::ACursoEspecial, SubjectStanding::CursoEspecialReprobado => KardexResult::Reprobada,
            default => null,
        };

        return $result === null ? null : $rows->where('result', $result)->sortBy([['term_code', 'desc'], ['attempt', 'desc']])->first();
    }

    /**
     * Las cadenas de seriación de la retícula que se muestra: cada grupo de dos o más materias unidas por su
     * seriación, en el orden de su primera materia en la retícula, y nombrado por ella.
     *
     * @param  list<string>  $shown
     * @param  array<string, list<string>>  $seriation
     * @param  Collection<string, Subject>  $subjects
     * @return array{0: array<string, int>, 1: list<array{index: int, name: string, size: int}>}
     */
    private function chains(array $shown, array $seriation, Collection $subjects): array
    {
        $root = array_combine($shown, $shown);
        $find = function (string $id) use (&$root): string {
            while ($root[$id] !== $id) {
                $id = $root[$id] = $root[$root[$id]];
            }

            return $id;
        };

        foreach ($shown as $subjectId) {
            foreach ($seriation[$subjectId] ?? [] as $prerequisite) {
                if (isset($root[$prerequisite])) {
                    $root[$find($subjectId)] = $find($prerequisite);
                }
            }
        }

        $groups = [];

        foreach ($shown as $subjectId) {
            $groups[$find($subjectId)][] = $subjectId;
        }

        $chainOf = [];
        $chains = [];

        foreach (array_values(array_filter($groups, fn (array $members): bool => count($members) > 1)) as $index => $members) {
            $chainOf += array_fill_keys($members, $index);
            $chains[] = ['index' => $index, 'name' => $subjects[$members[0]]->name, 'size' => count($members)];
        }

        return [$chainOf, $chains];
    }

    /**
     * @param  array<int, string>  $subjectIds
     * @param  Collection<string, Subject>  $subjects
     * @return list<string>
     */
    private function codes(array $subjectIds, Collection $subjects): array
    {
        return array_values(array_map(fn (string $id): string => $subjects[$id]->code, $subjectIds));
    }

    /**
     * @param  Collection<int, KardexRecord>  $records
     * @return list<array{term: string, label: string, records: list<KardexRecord>}>
     */
    private function kardex(Collection $records): array
    {
        return $records->sortBy([['term_code', 'desc'], fn (KardexRecord $a, KardexRecord $b): int => strcmp($a->subject->code, $b->subject->code)])
            ->groupBy('term_code')
            ->map(fn (Collection $rows, string $term): array => ['term' => $term, 'label' => self::termLabel($term), 'records' => $rows->values()->all()])
            ->values()
            ->all();
    }

    /**
     * El nombre de un periodo del SII: `20253` es «Ago–Dic 2025»; el último dígito es 1 Ene-Jun, 2 verano y 3
     * Ago-Dic.
     */
    private static function termLabel(string $code): string
    {
        return match (substr($code, -1)) {
            '1' => 'Ene–Jun',
            '2' => 'Verano',
            default => 'Ago–Dic',
        }.' '.substr($code, 0, 4);
    }
}
