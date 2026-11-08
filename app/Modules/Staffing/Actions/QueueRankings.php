<?php

namespace App\Modules\Staffing\Actions;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Faculty\Enums\QualificationLevel;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Models\TeacherSubjectQualification;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Jobs\ComputeRankings;
use App\Modules\Staffing\Models\StaffingRanking;
use Illuminate\Database\Query\Builder;

/**
 * Encola ComputeRankings para los alcances (periodo, campus y departamento) cuyo ranking docente cambió, cada uno una
 * sola vez y con 5 segundos de retraso para que una ráfaga de cambios espere un solo recálculo. No escribe nada.
 *
 * Los alcances de un docente son los departamentos donde es candidato (adscripciones vigentes y departamentos de las
 * materias donde es `preferente`) en cada campus con grupos no cancelados de ellos en el periodo, más los alcances
 * donde todavía aparece en el ranking: así se recalcula también donde dejó de ser candidato (perdió la adscripción
 * o el perfil). Sin periodo, cuentan los periodos con alguna carga en `proposed`, `staffing` o `adjusting`.
 */
final class QueueRankings
{
    public const int DELAY_SECONDS = 5;

    /**
     * Los estados de la carga en los que el ranking todavía sirve.
     *
     * @var list<LoadStatus>
     */
    public const array OPEN_STATUSES = [LoadStatus::Proposed, LoadStatus::Staffing, LoadStatus::Adjusting];

    public function forScope(string $termId, string $campusId, string $departmentId): void
    {
        $this->dispatch([[$termId, $campusId, $departmentId]]);
    }

    /**
     * El alcance del grupo y los de los docentes indicados (los del grupo, antes y después), en el periodo del grupo.
     *
     * @param  list<?string>  $teacherIds
     */
    public function forGroup(Group $group, array $teacherIds = []): void
    {
        $this->dispatch([
            [$group->term_id, $group->campus_id, $group->department_id],
            ...$this->teacherScopes(array_values(array_filter($teacherIds)), $group->term_id),
        ]);
    }

    /**
     * Los alcances de los docentes en un periodo o, sin él, en los periodos con carga abierta.
     *
     * @param  list<string>  $teacherIds
     */
    public function forTeachers(array $teacherIds, ?string $termId = null): void
    {
        $this->dispatch($this->teacherScopes($teacherIds, $termId));
    }

    /**
     * @param  list<string>  $teacherIds
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function teacherScopes(array $teacherIds, ?string $termId): array
    {
        if ($teacherIds === []) {
            return [];
        }

        $terms = fn (Builder $query, string $column) => $termId === null
            ? $query->whereIn($column, AcademicLoad::query()->whereIn('status', self::OPEN_STATUSES)->select('term_id'))
            : $query->where($column, $termId);

        $candidate = Group::query()->active()->toBase()
            ->where(fn (Builder $query) => $query
                ->whereIn('department_id', TeacherDepartment::query()->whereIn('teacher_id', $teacherIds)->select('department_id'))
                ->orWhereIn('department_id', TeacherSubjectQualification::query()
                    ->join('subjects', 'subjects.id', '=', 'teacher_subject_qualifications.subject_id')
                    ->whereIn('teacher_subject_qualifications.teacher_id', $teacherIds)
                    ->where('teacher_subject_qualifications.level', QualificationLevel::Preferente)
                    ->select('subjects.department_id')))
            ->tap(fn (Builder $query) => $terms($query, 'groups.term_id'))
            ->select(['groups.term_id', 'groups.campus_id', 'groups.department_id']);

        $ranked = StaffingRanking::query()->toBase()
            ->join('groups', 'groups.id', '=', 'staffing_rankings.group_id')
            ->whereIn('staffing_rankings.teacher_id', $teacherIds)
            ->tap(fn (Builder $query) => $terms($query, 'staffing_rankings.term_id'))
            ->select(['groups.term_id', 'groups.campus_id', 'groups.department_id']);

        return $candidate->union($ranked)->get()
            ->map(fn (object $row): array => [$row->term_id, $row->campus_id, $row->department_id])
            ->all();
    }

    /**
     * @param  iterable<array{0: string, 1: string, 2: string}>  $scopes
     */
    private function dispatch(iterable $scopes): void
    {
        $queued = [];

        foreach ($scopes as [$termId, $campusId, $departmentId]) {
            if (isset($queued[$key = "{$termId}:{$campusId}:{$departmentId}"])) {
                continue;
            }

            $queued[$key] = true;
            ComputeRankings::dispatch($termId, $campusId, $departmentId)->delay(self::DELAY_SECONDS);
        }
    }
}
