<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Enums\DemandProposalState;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Demand\Queries\DemandForecastsQuery;
use App\Modules\Demand\Queries\TermOccupancyQuery;
use App\Modules\Demand\Rules\DemandSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lecturas del tablero «Demanda por materia» (tarea 5.7): lo que proyecta Demand (DemandForecastsQuery) contra lo que
 * piden las propuestas de carrera (`proposal_items.requested_groups`), de todo el Tec, por materia, carrera y campus.
 * Arma el tablero en un número fijo de consultas, sin importar cuántas materias, carreras o campus haya (con el masivo,
 * unas 70 materias por 12 carreras por 2 campus en un departamento):
 *
 * - lo pedido cuenta solo de las propuestas enviadas, aceptadas o regresadas; una carrera sin propuesta o en borrador
 *   cuenta lo sugerido (DemandProposalState, supuesto por confirmar);
 * - una fila sin elegibles, sin esperados, sin sugeridos y sin nada pedido no aparece: es una materia que nadie puede
 *   llevar en el periodo (supuesto por confirmar, como la maqueta C);
 * - una materia que alguien pidió sin fila de proyección aparece con lo proyectado en cero.
 *
 * Quién lo ve lo deciden DemandBoardPolicy y la pantalla: aquí no se autoriza, salvo para listar los departamentos y
 * las carreras que el usuario puede elegir.
 */
final class DemandBoardQuery
{
    /** Los estados de la propuesta cuyo pedido cuenta (DemandProposalState::counts). */
    private const array COUNTED = [ProposalStatus::Submitted, ProposalStatus::Accepted, ProposalStatus::Returned];

    public function __construct(
        private readonly DemandForecastsQuery $forecasts,
        private readonly SubjectGroupHistoryQuery $groups,
        private readonly TermOccupancyQuery $occupancy,
    ) {}

    /**
     * Los departamentos activos cuyo tablero puede ver en la modalidad (`academic_load.demand.view_department`), por clave.
     *
     * @return Collection<int, Department>
     */
    public function departmentsFor(Actor $context, Modality $modality): Collection
    {
        return Department::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->filter(fn (Department $department): bool => $context->allows(AcademicLoadPermission::DemandViewDepartment, Target::of(modality: $modality->getKey(), unit: $department->getKey())))
            ->values();
    }

    /**
     * Las carreras ofrecidas en algún campus activo (como CareerProposalQuery::careersFor) cuya lente puede ver en la
     * modalidad (`academic_load.demand.view_career`), por clave.
     *
     * @return Collection<int, Career>
     */
    public function careersFor(Actor $context, Modality $modality): Collection
    {
        $offered = CareerCampus::query()
            ->select('career_id')
            ->where('is_active', true)
            ->whereIn('campus_id', Campus::query()->select('id')->where('is_active', true));

        return Career::query()
            ->whereIn('id', $offered)
            ->orderBy('code')
            ->get()
            ->filter(fn (Career $career): bool => $context->allows(AcademicLoadPermission::DemandViewCareer, Target::of(modality: $modality->getKey(), career: $career->getKey())))
            ->values();
    }

    /**
     * El periodo, la modalidad y el departamento o la carrera de la lente, con los ids que la página guardó al entrar.
     *
     * @return array{0: Term, 1: Modality, 2: ?Department, 3: ?Career}
     *
     * @throws ModelNotFoundException<Model>
     */
    public function scopeOf(string $termId, string $modalityId, ?string $departmentId, ?string $careerId): array
    {
        return [
            KeyLookup::findOrFail(Term::query(), $termId),
            KeyLookup::findOrFail(Modality::query()->withTrashed(), $modalityId),
            $departmentId === null ? null : KeyLookup::findOrFail(Department::query()->withTrashed(), $departmentId),
            $careerId === null ? null : KeyLookup::findOrFail(Career::query()->withTrashed(), $careerId),
        ];
    }

    /**
     * La lente del departamento: las materias que imparte, con todas las carreras que las llevan.
     */
    public function forDepartment(Term $term, Modality $modality, Department $department, ?DemandSettings $settings = null): DemandBoard
    {
        $forecasts = $this->forecasts->forDepartment($term, $modality, $department);
        $requested = $this->requested($term, $modality, fn (Builder $items) => $items
            ->whereIn('proposal_items.subject_id', Subject::query()->withTrashed()->select('id')->where('department_id', $department->getKey())));

        return $this->assemble($term, $modality, $forecasts, $requested, $settings ?? DemandSettings::fromSettings(), department: $department);
    }

    /**
     * La lente de la carrera: las materias que lleva (las de su proyección y las que pidió), de cualquier departamento,
     * con todas las carreras que las llevan.
     */
    public function forCareer(Term $term, Modality $modality, Career $career, ?DemandSettings $settings = null): DemandBoard
    {
        $own = $this->forecasts->forCareer($term, $modality, $career)->keys()->all();
        $asked = $this->requested($term, $modality, fn (Builder $items) => $items
            ->where('career_proposals.career_id', $career->getKey())
            ->where('proposal_items.requested_groups', '>', 0))
            ->pluck('subject_id')
            ->all();
        $subjectIds = array_values(array_unique([...$own, ...$asked]));

        $forecasts = $this->forecasts->forSubjects($term, $modality, $subjectIds);
        $requested = $subjectIds === [] ? collect() : $this->requested($term, $modality, fn (Builder $items) => $items->whereIn('proposal_items.subject_id', $subjectIds));

        return $this->assemble($term, $modality, $forecasts, $requested, $settings ?? DemandSettings::fromSettings(), career: $career);
    }

    /**
     * El historial de la materia en los periodos del mismo tipo (los de DemandSettings::historyTerms), por campus y del
     * más antiguo al más reciente: grupos, cupo e inscritos (SubjectGroupHistoryQuery) y cuántos alumnos la cursaron, la
     * aprobaron y la reprobaron (TermOccupancyQuery, del kárdex). Un dato que no hay es null. `label` es el tipo de
     * periodo («Ene-Jun»); en verano no hay historial.
     *
     * @return array{label: string, campuses: list<array{campus: ?Campus, terms: list<array{code: string, year: string, groups: ?int, capacity: ?int, enrolled: ?int, students: ?int, passed: ?int, failed: ?int}>}>}
     */
    public function history(DemandBoard $board, DemandBoardSubject $subject): array
    {
        $code = $board->term->code;
        $count = $board->settings->historyTerms;
        $terms = array_reverse(SubjectGroupHistoryQuery::sameTypeTermsBefore($code, $count));

        if ($terms === []) {
            return ['label' => '', 'campuses' => []];
        }

        $id = $subject->subject->getKey();
        $groups = $this->groups->history($code, $board->modality, [$id], $count)[$id] ?? [];
        $occupancy = $this->occupancy->history($code, $board->modality, [$id], $count)[$id] ?? [];

        $campusIds = array_map(fn (DemandBoardRow $row): string => $row->campusId() ?? '', $subject->rows);

        foreach ($groups as $byCampus) {
            $campusIds = [...$campusIds, ...array_map(fn (string $key): string => $key === SubjectGroupHistoryQuery::NO_CAMPUS ? '' : $key, array_keys($byCampus))];
        }

        foreach ($occupancy as $byCampus) {
            $campusIds = [...$campusIds, ...array_map('strval', array_keys($byCampus))];
        }

        $campusIds = array_values(array_unique($campusIds));
        $campuses = Campus::query()->withTrashed()->whereIn('id', array_filter($campusIds))->get()->keyBy('id');
        usort($campusIds, fn (string $a, string $b): int => [$a === '', $campuses[$a]->code ?? ''] <=> [$b === '', $campuses[$b]->code ?? '']);

        return [
            'label' => str_ends_with($code, '1') ? 'Ene-Jun' : 'Ago-Dic',
            'campuses' => array_map(fn (string $campusId): array => [
                'campus' => $campusId === '' ? null : $campuses[$campusId] ?? null,
                'terms' => array_map(function (string $term) use ($groups, $occupancy, $campusId): array {
                    $opened = $groups[$term][$campusId === '' ? SubjectGroupHistoryQuery::NO_CAMPUS : $campusId] ?? null;
                    $taken = $occupancy[$term][$campusId] ?? null;

                    return [
                        'code' => $term,
                        'year' => substr($term, 0, 4),
                        'groups' => $opened['groups'] ?? null,
                        'capacity' => $opened['capacity'] ?? null,
                        'enrolled' => $opened['enrolled'] ?? null,
                        'students' => $taken?->students,
                        'passed' => $taken?->passed,
                        'failed' => $taken?->failed,
                    ];
                }, $terms),
            ], $campusIds),
        ];
    }

    /**
     * Lo pedido en las propuestas que cuentan del periodo y la modalidad, por carrera, materia y campus, con el semestre de
     * la fila: una consulta.
     *
     * @param  callable(Builder): mixed  $scope
     * @return Collection<int, object{career_id: string, subject_id: string, campus_id: ?string, requested: int, semester: ?int}>
     */
    private function requested(Term $term, Modality $modality, callable $scope): Collection
    {
        $query = ProposalItem::query()
            ->join('career_proposals', 'career_proposals.id', '=', 'proposal_items.career_proposal_id')
            ->where('career_proposals.term_id', $term->getKey())
            ->where('career_proposals.modality_id', $modality->getKey())
            ->whereIn('career_proposals.status', array_map(fn (ProposalStatus $status): string => $status->value, self::COUNTED))
            ->toBase();
        $scope($query);

        return $query
            ->groupBy('career_proposals.career_id', 'proposal_items.subject_id', 'proposal_items.campus_id')
            ->get([
                'career_proposals.career_id',
                'proposal_items.subject_id',
                'proposal_items.campus_id',
                DB::raw('sum(proposal_items.requested_groups) as requested'),
                DB::raw('min(proposal_items.semester) as semester'),
            ])
            ->map(fn (object $row): object => (object) [
                'career_id' => $row->career_id,
                'subject_id' => $row->subject_id,
                'campus_id' => $row->campus_id,
                'requested' => (int) $row->requested,
                'semester' => $row->semester === null ? null : (int) $row->semester,
            ]);
    }

    /**
     * Junta la proyección y lo pedido en filas por materia, carrera y campus, con el estado de la propuesta de cada carrera
     * y el semestre de la materia en su retícula.
     *
     * @param  Collection<string, Collection<int, DemandForecast>>  $forecasts
     * @param  Collection<int, object{career_id: string, subject_id: string, campus_id: ?string, requested: int, semester: ?int}>  $requested
     */
    private function assemble(Term $term, Modality $modality, Collection $forecasts, Collection $requested, DemandSettings $settings, ?Department $department = null, ?Career $career = null): DemandBoard
    {
        $keyOf = fn (string $subjectId, string $careerId, ?string $campusId): string => "{$subjectId}|{$careerId}|".($campusId ?? '');
        $forecastRows = $forecasts->flatten(1)->keyBy(fn (DemandForecast $forecast): string => $keyOf($forecast->subject_id, $forecast->career_id, $forecast->campus_id));
        $requestedRows = $requested->keyBy(fn (object $row): string => $keyOf($row->subject_id, $row->career_id, $row->campus_id));

        $careerIds = array_values(array_unique([...$forecastRows->pluck('career_id')->all(), ...$requestedRows->pluck('career_id')->all()]));
        $subjectIds = array_values(array_unique([...$forecastRows->pluck('subject_id')->all(), ...$requestedRows->pluck('subject_id')->all()]));

        $careers = $forecastRows->mapWithKeys(fn (DemandForecast $forecast): array => [$forecast->career_id => $forecast->career])->filter();
        $campuses = $forecastRows->mapWithKeys(fn (DemandForecast $forecast): array => $forecast->campus === null ? [] : [$forecast->campus_id => $forecast->campus]);
        $missingCareers = array_values(array_diff($careerIds, $careers->keys()->all()));
        $missingCampuses = array_values(array_diff(array_filter($requestedRows->pluck('campus_id')->all()), $campuses->keys()->all()));

        if ($missingCareers !== []) {
            $careers = $careers->merge(Career::query()->withTrashed()->whereIn('id', $missingCareers)->get()->keyBy('id'));
        }

        if ($missingCampuses !== []) {
            $campuses = $campuses->merge(Campus::query()->withTrashed()->whereIn('id', $missingCampuses)->get()->keyBy('id'));
        }

        $states = $careerIds === [] ? collect() : CareerProposal::query()
            ->where('term_id', $term->getKey())
            ->where('modality_id', $modality->getKey())
            ->whereIn('career_id', $careerIds)
            ->get(['career_id', 'status'])
            ->mapWithKeys(fn (CareerProposal $proposal): array => [$proposal->career_id => $proposal->status]);
        $stateOf = fn (string $careerId): DemandProposalState => DemandProposalState::of($states[$careerId] ?? null);

        $semesters = $this->semesters($careerIds, $subjectIds);
        $subjects = $subjectIds === [] ? collect() : Subject::query()->withTrashed()
            ->with(['department' => fn ($query) => $query->withTrashed()])
            ->whereIn('id', $subjectIds)
            ->get()
            ->keyBy('id');

        $rows = [];

        foreach (array_unique([...$forecastRows->keys()->all(), ...$requestedRows->keys()->all()]) as $key) {
            [$subjectId, $careerId, $campusId] = explode('|', $key);
            $forecast = $forecastRows[$key] ?? null;
            $asked = $requestedRows[$key] ?? null;
            $state = $stateOf($careerId);
            $row = new DemandBoardRow(
                career: $careers[$careerId],
                campus: $campusId === '' ? null : $campuses[$campusId] ?? null,
                state: $state,
                semester: $asked?->semester ?? $semesters["{$careerId}|{$subjectId}"] ?? null,
                eligible: $forecast->eligible_students ?? 0,
                expected: $forecast->expected_students ?? 0,
                suggested: $forecast->suggested_groups ?? 0,
                requested: $state->counts() ? ($asked->requested ?? 0) : null,
                method: $forecast?->method,
                needsReview: (bool) ($forecast?->needs_review),
            );

            if ($row->eligible > 0 || $row->expected > 0 || $row->suggested > 0 || (int) $row->requested > 0) {
                $rows[$subjectId][] = $row;
            }
        }

        $boardSubjects = [];

        foreach ($rows as $subjectId => $subjectRows) {
            $first = fn (DemandBoardRow $row): int => $career !== null && $row->career->getKey() === $career->getKey() ? 0 : 1;
            usort($subjectRows, fn (DemandBoardRow $a, DemandBoardRow $b): int => [$first($a), $a->career->code, $a->campus->code ?? ''] <=> [$first($b), $b->career->code, $b->campus->code ?? '']);
            $lensRow = $career === null ? null : collect($subjectRows)->first(fn (DemandBoardRow $row): bool => $row->career->getKey() === $career->getKey());
            $boardSubjects[] = new DemandBoardSubject($subjects[$subjectId], $lensRow?->semester, $subjectRows, $settings);
        }

        usort($boardSubjects, fn (DemandBoardSubject $a, DemandBoardSubject $b): int => $career === null
            ? strcmp($a->subject->code, $b->subject->code)
            : [$a->semester ?? PHP_INT_MAX, $a->subject->code] <=> [$b->semester ?? PHP_INT_MAX, $b->subject->code]);

        $kept = collect($rows)->flatten(1);
        $columns = $kept->map(fn (DemandBoardRow $row): Career => $row->career)->unique(fn (Career $column): string => $column->getKey())
            ->sortBy(fn (Career $column): string => ($career !== null && $column->getKey() === $career->getKey() ? '0' : '1').$column->code)
            ->map(fn (Career $column): array => ['career' => $column, 'state' => $stateOf($column->getKey())])
            ->values()
            ->all();
        $boardCampuses = $kept->map(fn (DemandBoardRow $row): ?Campus => $row->campus)->filter()->unique(fn (Campus $campus): string => $campus->getKey())
            ->sortBy('code')->values()->all();
        $computedAt = $forecastRows->max(fn (DemandForecast $forecast): ?CarbonImmutable => $forecast->computed_at);

        return new DemandBoard($term, $modality, $department, $career, $columns, $boardSubjects, $boardCampuses, $computedAt, $settings);
    }

    /**
     * El semestre de cada materia en la retícula de cada carrera, por «<id de carrera>|<id de materia>»: el de su primer
     * plan activo por clave, como la proyección (ForecastScopeQuery::subjectsFor). Una consulta.
     *
     * @param  list<string>  $careerIds
     * @param  list<string>  $subjectIds
     * @return array<string, int>
     */
    private function semesters(array $careerIds, array $subjectIds): array
    {
        if ($careerIds === [] || $subjectIds === []) {
            return [];
        }

        $semesters = [];

        StudyPlanSubject::query()
            ->join('study_plans', 'study_plans.id', '=', 'study_plan_subjects.study_plan_id')
            ->whereIn('study_plans.career_id', $careerIds)
            ->whereIn('study_plan_subjects.subject_id', $subjectIds)
            ->where('study_plans.is_active', true)
            ->whereNull('study_plans.deleted_at')
            ->orderBy('study_plans.code')
            ->orderBy('study_plan_subjects.semester')
            ->toBase()
            ->get(['study_plans.career_id', 'study_plan_subjects.subject_id', 'study_plan_subjects.semester'])
            ->each(function (object $row) use (&$semesters): void {
                $semesters["{$row->career_id}|{$row->subject_id}"] ??= (int) $row->semester;
            });

        return $semesters;
    }
}
