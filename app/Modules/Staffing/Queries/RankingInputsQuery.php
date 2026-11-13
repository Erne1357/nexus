<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Enums\QualificationLevel;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Models\TeacherEvaluation;
use App\Modules\Faculty\Models\TeacherSubjectHistory;
use App\Modules\Faculty\Models\TeacherSubjectQualification;
use App\Modules\Faculty\Queries\TeacherBaseHoursQuery;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Models\StaffingRanking;
use App\Modules\Staffing\Rules\RankingWindow;
use App\Modules\Staffing\Rules\RankingWindowSizes;
use Illuminate\Support\Collection;

/**
 * Los insumos de Faculty del ranking docente para los grupos de un periodo (reglas-de-negocio.md, «Ranking docente
 * (desempate)»), en un número fijo de consultas por lotes, sin importar cuántos grupos ni docentes sean.
 *
 * - Candidatos de un grupo: docentes activos y no borrados con adscripción vigente al departamento del grupo, y los
 *   activos de otros departamentos con perfil `preferente` en la materia (`fromOtherDepartment`).
 * - Historial: filas de `teacher_subject_history` de la materia dentro de `RankingWindow::semestersBefore` con el
 *   tamaño de `staffing.history_terms`; de cualquier campus y origen (supuesto por confirmar, ya documentado).
 * - Evaluación: promedio simple de todas las evaluaciones de la ventana de `staffing.evaluation_terms` (supuesto
 *   por confirmar, ya documentado); null si no hay.
 * - Puesto anterior: el mejor puesto del docente en los grupos de la misma materia, la misma modalidad y el mismo
 *   campus del semestre anterior (MS27, supuesto por confirmar; el campus y la modalidad son los de los grupos); null
 *   si ese periodo o ese ranking no existen.
 * - Los grupos cancelados no tienen entrada.
 */
final class RankingInputsQuery
{
    public function __construct(private readonly TeacherBaseHoursQuery $baseHours) {}

    /**
     * @param  Collection<int, Group>  $groups  grupos de un mismo periodo
     * @return array<string, list<RankingInput>> por id de grupo
     */
    public function forGroups(Term $term, Collection $groups): array
    {
        $groups = $groups->reject(fn (Group $group): bool => $group->status === GroupStatus::Cancelled)->values();

        if ($groups->isEmpty()) {
            return [];
        }

        $departmentIds = $groups->pluck('department_id')->unique()->values()->all();
        $subjectIds = $groups->pluck('subject_id')->unique()->values()->all();
        $sizes = RankingWindowSizes::fromSettings();

        /** @var array<string, list<string>> $adscribed department id => teacher ids */
        $adscribed = TeacherDepartment::query()
            ->join('teachers', 'teachers.id', '=', 'teacher_departments.teacher_id')
            ->whereIn('teacher_departments.department_id', $departmentIds)
            ->where('teachers.is_active', true)
            ->whereNull('teachers.deleted_at')
            ->get(['teacher_departments.department_id', 'teacher_departments.teacher_id'])
            ->groupBy('department_id')
            ->map(fn (Collection $rows): array => $rows->pluck('teacher_id')->unique()->all())
            ->all();

        /** @var array<string, QualificationLevel> $levels "subject|teacher" => level */
        $levels = [];
        /** @var array<string, list<string>> $preferred subject id => teacher ids */
        $preferred = [];
        $qualifications = TeacherSubjectQualification::query()
            ->join('teachers', 'teachers.id', '=', 'teacher_subject_qualifications.teacher_id')
            ->whereIn('teacher_subject_qualifications.subject_id', $subjectIds)
            ->where('teachers.is_active', true)
            ->whereNull('teachers.deleted_at')
            ->get(['teacher_subject_qualifications.subject_id', 'teacher_subject_qualifications.teacher_id', 'teacher_subject_qualifications.level']);

        foreach ($qualifications as $row) {
            $levels[$row->subject_id.'|'.$row->teacher_id] = $row->level;

            if ($row->level === QualificationLevel::Preferente) {
                $preferred[$row->subject_id][] = $row->teacher_id;
            }
        }

        $teacherIds = collect($adscribed)->flatten()->concat(collect($preferred)->flatten())->unique()->values()->all();

        if ($teacherIds === []) {
            return $groups->mapWithKeys(fn (Group $group): array => [$group->id => []])->all();
        }

        $names = Teacher::query()->whereIn('id', $teacherIds)->pluck('name', 'id');
        $hours = $this->baseHours->forTerm($term, $teacherIds);

        $history = TeacherSubjectHistory::query()
            ->whereIn('teacher_id', $teacherIds)
            ->whereIn('subject_id', $subjectIds)
            ->whereIn('term_code', RankingWindow::semestersBefore($term->code, $sizes->history))
            ->selectRaw('teacher_id, subject_id, count(*) as total')
            ->groupBy('teacher_id', 'subject_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [$row->subject_id.'|'.$row->teacher_id => (int) $row->total]);

        $evaluations = TeacherEvaluation::query()
            ->whereIn('teacher_id', $teacherIds)
            ->whereIn('term_code', RankingWindow::semestersBefore($term->code, $sizes->evaluation))
            ->selectRaw('teacher_id, avg(score) as average')
            ->groupBy('teacher_id')
            ->pluck('average', 'teacher_id');

        $previousRanks = $this->previousRanks($term, $groups, $teacherIds);

        return $groups->mapWithKeys(function (Group $group) use ($adscribed, $preferred, $names, $hours, $history, $evaluations, $levels, $previousRanks): array {
            $own = $adscribed[$group->department_id] ?? [];
            $others = array_diff($preferred[$group->subject_id] ?? [], $own);
            $inputs = [];

            foreach ([[$own, false], [$others, true]] as [$ids, $fromOther]) {
                foreach (array_unique($ids) as $teacherId) {
                    $key = $group->subject_id.'|'.$teacherId;
                    $average = $evaluations[$teacherId] ?? null;
                    $inputs[] = new RankingInput(
                        $teacherId,
                        (string) $names[$teacherId],
                        $hours[$teacherId] ?? 0,
                        $history[$key] ?? 0,
                        $levels[$key] ?? null,
                        $average === null ? null : round((float) $average, 2),
                        $previousRanks[$group->modality_id.'|'.$group->campus_id.'|'.$key] ?? null,
                        $fromOther,
                    );
                }
            }

            return [$group->id => $inputs];
        })->all();
    }

    /**
     * @param  Collection<int, Group>  $groups
     * @param  list<string>  $teacherIds
     * @return array<string, int> "modality|campus|subject|teacher" => mejor puesto
     */
    private function previousRanks(Term $term, Collection $groups, array $teacherIds): array
    {
        $previousCode = RankingWindow::semestersBefore($term->code, 1)[0];
        $previousTermId = Term::query()->where('code', $previousCode)->value('id');

        if ($previousTermId === null) {
            return [];
        }

        return StaffingRanking::query()
            ->join('groups', 'groups.id', '=', 'staffing_rankings.group_id')
            ->where('staffing_rankings.term_id', $previousTermId)
            ->whereIn('groups.modality_id', $groups->pluck('modality_id')->unique()->values()->all())
            ->whereIn('groups.campus_id', $groups->pluck('campus_id')->unique()->values()->all())
            ->whereIn('groups.subject_id', $groups->pluck('subject_id')->unique()->values()->all())
            ->whereIn('staffing_rankings.teacher_id', $teacherIds)
            ->selectRaw('groups.modality_id, groups.campus_id, groups.subject_id, staffing_rankings.teacher_id, min(staffing_rankings.rank) as best')
            ->groupBy('groups.modality_id', 'groups.campus_id', 'groups.subject_id', 'staffing_rankings.teacher_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [$row->modality_id.'|'.$row->campus_id.'|'.$row->subject_id.'|'.$row->teacher_id => (int) $row->best])
            ->all();
    }
}
