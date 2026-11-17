<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\EvaluateForecast;
use App\Modules\AcademicLoad\Enums\ForecastEvaluationStatus;
use App\Modules\AcademicLoad\Jobs\RunForecastEvaluation;
use App\Modules\AcademicLoad\Models\ForecastEvaluation;
use App\Modules\AcademicLoad\Models\ForecastEvaluationRow;
use App\Modules\AcademicLoad\Rules\ForecastAccuracy;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Demand\Enums\ForecastMethod;
use App\Modules\Demand\Rules\TermCode;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Las lecturas de «Precisión de la proyección» (tarea 5.9c; DM-20 a DM-24). Lee las evaluaciones y su `totals` (la forma
 * está en EvaluateForecast): las métricas por carrera, por campus y por departamento salen de ahí; las filas solo se leen
 * para lo que `totals` no trae (un campus y un departamento a la vez, los conteos del bloque de arriba y las materias de
 * una carrera).
 *
 * El filtro de departamento es el de la materia, el que abre sus grupos, como `totals.by_department` (confirmado por el
 * usuario el 17 de noviembre de 2026): con él, cada carrera cuenta solo sus materias de ese departamento.
 */
final class ForecastEvaluationsQuery
{
    /** Cuántas evaluaciones lista el drawer «Evaluaciones», de la más reciente a la más antigua (supuesto por confirmar). */
    public const int LIST_LIMIT = 50;

    public function __construct(private readonly TermKardexQuery $kardex) {}

    /**
     * Las evaluaciones, la más reciente primero.
     *
     * @return Collection<int, ForecastEvaluation>
     */
    public function list(): Collection
    {
        return $this->withRelations(ForecastEvaluation::query())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get();
    }

    /**
     * La evaluación de la pantalla: la pedida (`?evaluacion=`, aunque ya no esté entre las de la lista); si no, la primera
     * que sigue en curso; si no, la última lista, y si no, la más reciente.
     *
     * @param  Collection<int, ForecastEvaluation>  $list
     */
    public function chosen(Collection $list, ?string $id): ?ForecastEvaluation
    {
        $requested = filled($id) ? $this->evaluation($id) : null;

        return $requested
            ?? $list->first(fn (ForecastEvaluation $evaluation): bool => $evaluation->status->isPending())
            ?? $list->first(fn (ForecastEvaluation $evaluation): bool => $evaluation->status === ForecastEvaluationStatus::Done)
            ?? $list->first();
    }

    /**
     * Una evaluación por su id (KeyLookup), con su periodo, modalidad, campus y quien la pidió; null si no existe.
     */
    public function evaluation(string $id): ?ForecastEvaluation
    {
        return KeyLookup::find($this->withRelations(ForecastEvaluation::query()), $id);
    }

    /**
     * El periodo que se eligió en «Evaluar un periodo», por su id (KeyLookup); null si no existe.
     */
    public function term(string $id): ?Term
    {
        return KeyLookup::find(Term::query(), $id);
    }

    /**
     * Si la carrera tiene resultados en la evaluación (una entrada de `totals.by_career`): solo esas abren sus materias.
     */
    public function hasCareer(ForecastEvaluation $evaluation, string $careerId): bool
    {
        return $this->entry($evaluation->totals['by_career'] ?? [], 'career_id', $careerId) !== null;
    }

    /**
     * Cada cuánto vuelve a leer la pantalla mientras algo corre: cada 2 s si la evaluación elegida sigue en cola o
     * corriendo; cada 10 s si la elegida no, pero otra de la lista sí (para el punto de «Evaluaciones»); nunca si nada
     * corre. Una en cola o corriendo que no se movió en `RunForecastEvaluation::STALE_AFTER` segundos se da por perdida
     * (RequestForecastEvaluation) y no hace consultar a la pantalla.
     *
     * @param  Collection<int, ForecastEvaluation>  $list
     * @return '2s'|'10s'|null
     */
    public function pollEvery(Collection $list, ?ForecastEvaluation $chosen): ?string
    {
        $moving = fn (ForecastEvaluation $evaluation): bool => $evaluation->status->isPending()
            && $evaluation->updated_at->gte(now()->subSeconds(RunForecastEvaluation::STALE_AFTER));

        return match (true) {
            $chosen !== null && $moving($chosen) => '2s',
            $list->contains(fn (ForecastEvaluation $evaluation): bool => $evaluation->getKey() !== $chosen?->getKey() && $moving($evaluation)) => '10s',
            default => null,
        };
    }

    public function item(ForecastEvaluation $evaluation): ForecastEvaluationItem
    {
        $totals = $evaluation->totals ?? [];
        $requested = $evaluation->created_at->timezone(config('app.timezone'));

        return new ForecastEvaluationItem(
            id: $evaluation->getKey(),
            termId: $evaluation->term_id,
            term: $evaluation->term->name,
            termCode: $evaluation->term->code,
            modalityCode: $evaluation->modality->code,
            modality: $evaluation->modality->name,
            campusCode: $evaluation->campus?->code,
            campus: $evaluation->campus->name ?? 'Todos los campus',
            status: $evaluation->status,
            progress: (int) $evaluation->progress,
            stage: $evaluation->stage,
            requestedAt: $requested->format('d/m/Y').' a las '.$requested->format('G:i'),
            requestedBy: $evaluation->requester->name ?? 'Desde la consola',
            took: $evaluation->status === ForecastEvaluationStatus::Done && $evaluation->started_at !== null && $evaluation->finished_at !== null
                ? self::duration($evaluation->started_at, $evaluation->finished_at) : null,
            elapsed: $evaluation->status === ForecastEvaluationStatus::Running && $evaluation->started_at !== null
                ? self::duration($evaluation->started_at, now()) : null,
            overall: isset($totals['overall']) ? ForecastMetrics::fromTotals($totals['overall']) : null,
            error: $evaluation->error,
            careersDone: (int) ($totals['careers']['done'] ?? 0),
            careersTotal: isset($totals['careers']['total']) ? (int) $totals['careers']['total'] : null,
            outOfScopeStudents: (int) ($totals['out_of_scope']['students'] ?? 0),
        );
    }

    /**
     * Lo que pinta la pantalla de una evaluación. Los filtros solo aplican a una evaluación lista: mientras corre, cada
     * carrera terminada va con lo de todos sus campus. El campus solo filtra una evaluación de todos los campus.
     */
    public function report(ForecastEvaluation $evaluation, ?string $campusCode = null, ?string $departmentCode = null): ForecastReport
    {
        $totals = $evaluation->totals ?? [];
        $byCareer = $totals['by_career'] ?? [];
        $done = $evaluation->status === ForecastEvaluationStatus::Done;

        $campusCounts = [];
        foreach ($byCareer as $entry) {
            foreach ($entry['by_campus'] ?? [] as $inCampus) {
                if ($inCampus['campus_id'] !== null) {
                    $campusCounts[$inCampus['campus_id']] = ($campusCounts[$inCampus['campus_id']] ?? 0) + 1;
                }
            }
        }

        $campuses = Campus::withTrashed()->whereIn('id', array_keys($campusCounts))->orderBy('code')->get();
        $departments = Department::withTrashed()->whereIn('id', array_filter(array_column($totals['by_department'] ?? [], 'department_id')))->orderBy('code')->get();
        $campus = $done && $evaluation->campus_id === null && filled($campusCode) ? $campuses->firstWhere('code', $campusCode) : null;
        $department = $done && filled($departmentCode) ? $departments->firstWhere('code', $departmentCode) : null;
        $careers = Career::withTrashed()->whereIn('id', array_column($byCareer, 'career_id'))->get()->keyBy('id');
        $campusById = $campuses->keyBy('id');

        if ($department === null) {
            $careerAccuracy = $this->careersFromTotals($byCareer, $careers, $campusById, $campus);
            $summary = ! $done ? null : match (true) {
                $campus === null => ForecastMetrics::fromTotals($totals['overall'] ?? []),
                default => ForecastMetrics::fromTotals($this->entry($totals['by_campus'] ?? [], 'campus_id', $campus->getKey()) ?? []),
            };
        } else {
            $rows = $this->rows($evaluation, $campus, $department);
            $careerAccuracy = $this->careersFromRows($rows, $careers, $campusById, $campus);
            $summary = $campus === null
                ? ForecastMetrics::fromTotals($this->entry($totals['by_department'] ?? [], 'department_id', $department->getKey()) ?? [])
                : ForecastMetrics::of(array_map(fn (object $row): array => ['projected' => (int) $row->projected, 'actual' => (int) $row->actual_students, 'baseline' => (int) $row->baseline], $rows));
        }

        usort($careerAccuracy, fn (array $a, array $b): int => [$a['metrics']->weightedError === null, $a['metrics']->weightedError, $a['career']->code] <=> [$b['metrics']->weightedError === null, $b['metrics']->weightedError, $b['career']->code]);

        return new ForecastReport(
            summary: $summary,
            counts: $summary === null ? null : $this->counts($evaluation, $summary, $campus, $department),
            careers: $careerAccuracy,
            campuses: $campuses,
            campusCounts: collect($campusCounts)->mapWithKeys(fn (int $count, string $id): array => [$campusById[$id]->code => $count])->all(),
            departments: $departments,
            campus: $campus,
            department: $department,
        );
    }

    /**
     * Las materias de una carrera en la evaluación, en el campus del drawer (o el de la página) y con el departamento de
     * la página. Null si la carrera no tiene filas con esos filtros.
     */
    public function subjects(ForecastEvaluation $evaluation, string $careerId, ?string $campusCode = null, ?string $departmentCode = null): ?CareerSubjects
    {
        $career = KeyLookup::find(Career::withTrashed()->with(['department' => fn ($query) => $query->withTrashed()]), $careerId);
        $entry = $this->entry($evaluation->totals['by_career'] ?? [], 'career_id', $career?->getKey());

        if ($career === null || $entry === null) {
            return null;
        }

        $campuses = Campus::withTrashed()->whereIn('id', array_filter(array_column($entry['by_campus'] ?? [], 'campus_id')))->orderBy('code')->get();
        $campus = filled($campusCode) ? $campuses->firstWhere('code', $campusCode) : null;
        $department = filled($departmentCode) ? Department::withTrashed()->where('code', $departmentCode)->first() : null;

        $rows = ForecastEvaluationRow::query()
            ->where('evaluation_id', $evaluation->getKey())
            ->where('career_id', $career->getKey())
            ->when($campus !== null, fn (Builder $query) => $query->where('campus_id', $campus->getKey()))
            ->when($department !== null, fn (Builder $query) => $query->whereIn('subject_id', Subject::withTrashed()->select('id')->where('department_id', $department->getKey())))
            ->with(['subject' => fn ($query) => $query->withTrashed()->with(['department' => fn ($inner) => $inner->withTrashed()]), 'campus' => fn ($query) => $query->withTrashed()])
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $subjects = $rows->map(fn (ForecastEvaluationRow $row): array => $this->subject($row))->all();
        usort($subjects, fn (array $a, array $b): int => $this->errorRank($b) <=> $this->errorRank($a) ?: [$b['actual'], $a['code']] <=> [$a['actual'], $b['code']]);

        return new CareerSubjects(
            career: $career,
            metrics: ForecastMetrics::of($rows->map(fn (ForecastEvaluationRow $row): array => ['projected' => $row->projected, 'actual' => $row->actual_students, 'baseline' => $row->baseline])->all()),
            subjects: $subjects,
            campuses: $campuses,
            campus: $campus,
        );
    }

    /**
     * Los periodos que se pueden evaluar en la modalidad (DM-28): con kárdex contable, que son periodo de Nexus y no de
     * verano, el más reciente primero.
     *
     * @return Collection<int, Term>
     */
    public function evaluableTerms(Modality $modality): Collection
    {
        $codes = array_values(array_filter($this->kardex->termCodes($modality), fn (string $code): bool => TermCode::isRegular($code)));

        return Term::query()->whereIn('code', $codes)->orderByDesc('code')->get();
    }

    /**
     * Cuándo se terminó por última vez la evaluación de cada periodo en la modalidad y el campus (null: todos), por id del
     * periodo: «Evaluado el 16/11/2026 a las 8:47» y «Ya la evaluaste…» en «Evaluar un periodo».
     *
     * @return array<string, string>
     */
    public function evaluatedOn(Modality $modality, ?Campus $campus): array
    {
        return ForecastEvaluation::query()
            ->toBase()
            ->where('modality_id', $modality->getKey())
            ->when($campus === null, fn (QueryBuilder $query) => $query->whereNull('campus_id'), fn (QueryBuilder $query) => $query->where('campus_id', $campus->getKey()))
            ->where('status', ForecastEvaluationStatus::Done->value)
            ->groupBy('term_id')
            ->selectRaw('term_id, max(finished_at) as finished_at')
            ->get()
            ->mapWithKeys(function (object $row): array {
                $finished = Carbon::parse($row->finished_at)->timezone(config('app.timezone'));

                return [$row->term_id => $finished->format('d/m/Y').' a las '.$finished->format('G:i')];
            })
            ->all();
    }

    /**
     * El nombre del método con que se proyectó una materia; null para la fórmula con su historia, que es lo normal y no
     * se marca en la lista. «Sin proyección» es una materia del alcance que alguien cursó sin fila proyectada.
     */
    public static function methodLabel(string $method): ?string
    {
        return match ($method) {
            ForecastMethod::Formula->value => null,
            EvaluateForecast::NOT_PROJECTED => 'Sin proyección',
            default => ForecastMethod::tryFrom($method)?->label() ?? $method,
        };
    }

    /**
     * «2 min 41 s», «58 s» o «1 h 3 min».
     */
    public static function duration(CarbonInterface $from, CarbonInterface $to): string
    {
        $seconds = max(0, (int) $from->diffInSeconds($to, true));

        return match (true) {
            $seconds < 60 => "{$seconds} s",
            $seconds < 3600 => intdiv($seconds, 60).' min '.($seconds % 60).' s',
            default => intdiv($seconds, 3600).' h '.intdiv($seconds % 3600, 60).' min',
        };
    }

    /**
     * @param  Builder<ForecastEvaluation>  $query
     * @return Builder<ForecastEvaluation>
     */
    private function withRelations(Builder $query): Builder
    {
        return $query->with([
            'term',
            'modality' => fn ($relation) => $relation->withTrashed(),
            'campus' => fn ($relation) => $relation->withTrashed(),
            'requester',
        ]);
    }

    /**
     * Cada carrera de `by_career`, con lo de todos sus campus o con lo del campus del filtro.
     *
     * @param  list<array<string, mixed>>  $byCareer
     * @param  Collection<string, Career>  $careers
     * @param  Collection<string, Campus>  $campuses
     * @return list<array{career: Career, metrics: ForecastMetrics, byCampus: list<array{campus: Campus, metrics: ForecastMetrics}>}>
     */
    private function careersFromTotals(array $byCareer, Collection $careers, Collection $campuses, ?Campus $campus): array
    {
        $result = [];

        foreach ($byCareer as $entry) {
            $career = $careers[$entry['career_id']] ?? null;
            $inCampus = $campus === null ? $entry : $this->entry($entry['by_campus'] ?? [], 'campus_id', $campus->getKey());

            if ($career === null || $inCampus === null) {
                continue;
            }

            $result[] = [
                'career' => $career,
                'metrics' => ForecastMetrics::fromTotals($inCampus),
                'byCampus' => $campus !== null ? [] : array_values(array_filter(array_map(
                    fn (array $byCampus): ?array => isset($campuses[$byCampus['campus_id'] ?? '']) ? ['campus' => $campuses[$byCampus['campus_id']], 'metrics' => ForecastMetrics::fromTotals($byCampus)] : null,
                    $entry['by_campus'] ?? [],
                ))),
            ];
        }

        return $result;
    }

    /**
     * Cada carrera con sus filas del departamento (y del campus) del filtro.
     *
     * @param  list<object>  $rows
     * @param  Collection<string, Career>  $careers
     * @param  Collection<string, Campus>  $campuses
     * @return list<array{career: Career, metrics: ForecastMetrics, byCampus: list<array{campus: Campus, metrics: ForecastMetrics}>}>
     */
    private function careersFromRows(array $rows, Collection $careers, Collection $campuses, ?Campus $campus): array
    {
        $metricsOf = fn (Collection $group): ForecastMetrics => ForecastMetrics::of($group->map(fn (object $row): array => ['projected' => (int) $row->projected, 'actual' => (int) $row->actual_students, 'baseline' => (int) $row->baseline])->values()->all());
        $result = [];

        foreach (collect($rows)->groupBy('career_id') as $careerId => $group) {
            if (! isset($careers[$careerId])) {
                continue;
            }

            $result[] = [
                'career' => $careers[$careerId],
                'metrics' => $metricsOf($group),
                'byCampus' => $campus !== null ? [] : $group->groupBy('campus_id')
                    ->filter(fn (Collection $inCampus, string $campusId): bool => isset($campuses[$campusId]))
                    ->sortKeysUsing(fn (string $a, string $b): int => $campuses[$a]->code <=> $campuses[$b]->code)
                    ->map(fn (Collection $inCampus, string $campusId): array => ['campus' => $campuses[$campusId], 'metrics' => $metricsOf($inCampus)])
                    ->values()->all(),
            ];
        }

        return $result;
    }

    /**
     * Las filas de la evaluación del departamento de la materia (y del campus), solo con lo que se mide.
     *
     * @return list<object>
     */
    private function rows(ForecastEvaluation $evaluation, ?Campus $campus, Department $department): array
    {
        return $this->filteredRows($evaluation, $campus, $department)
            ->get(['career_id', 'campus_id', 'projected', 'actual_students', 'baseline'])
            ->all();
    }

    /**
     * Para el bloque de arriba: las materias que cuentan (DM-21), las que tienen alumnos, cuántas de ellas quedaron
     * dentro del 15 % (DM-23) y los grupos sugeridos y abiertos. Los abiertos son nulos si ninguna fila los trae: salen del
     * historial de grupos, que todavía no llega (EvaluateForecast).
     *
     * @return array{counted: int, withStudents: int, within: int, suggestedGroups: int, openedGroups: ?int}
     */
    private function counts(ForecastEvaluation $evaluation, ForecastMetrics $summary, ?Campus $campus, ?Department $department): array
    {
        $row = $this->filteredRows($evaluation, $campus, $department)
            ->selectRaw('count(*) filter (where actual_students > 0 or projected > 0) as counted')
            ->selectRaw('count(*) filter (where actual_students > 0) as with_students')
            ->selectRaw('coalesce(sum(suggested_groups), 0) as suggested_groups')
            ->selectRaw('sum(actual_groups) as opened_groups')
            ->first();
        $withStudents = (int) $row->with_students;

        return [
            'counted' => (int) $row->counted,
            'withStudents' => $withStudents,
            'within' => (int) round(($summary->withinShare ?? 0) * $withStudents),
            'suggestedGroups' => (int) $row->suggested_groups,
            'openedGroups' => $row->opened_groups === null ? null : (int) $row->opened_groups,
        ];
    }

    private function filteredRows(ForecastEvaluation $evaluation, ?Campus $campus, ?Department $department): QueryBuilder
    {
        return ForecastEvaluationRow::query()
            ->toBase()
            ->where('evaluation_id', $evaluation->getKey())
            ->when($campus !== null, fn (QueryBuilder $query) => $query->where('campus_id', $campus->getKey()))
            ->when($department !== null, fn (QueryBuilder $query) => $query->whereIn('subject_id', Subject::withTrashed()->select('id')->where('department_id', $department->getKey())));
    }

    /**
     * La entrada de una lista de `totals` con esa llave.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>|null
     */
    private function entry(array $entries, string $key, ?string $id): ?array
    {
        foreach ($entries as $entry) {
            if (($entry[$key] ?? null) === $id) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return array{code: string, name: string, department: ?string, campus: ?Campus, eligible: int, projected: int, actual: int, suggestedGroups: int, actualGroups: ?int, method: string, methodLabel: ?string, error: ?float, out: bool, noHistory: bool, counts: bool}
     */
    private function subject(ForecastEvaluationRow $row): array
    {
        $projected = $row->projected;
        $actual = $row->actual_students;
        $error = $actual > 0 ? ($projected - $actual) / $actual : null;
        $withinPercent = (int) round(ForecastAccuracy::STRONG_THRESHOLD * 100);

        return [
            'code' => $row->subject->code,
            'name' => $row->subject->name,
            'department' => $row->subject->department?->code,
            'campus' => $row->campus,
            'eligible' => $row->eligible,
            'projected' => $projected,
            'actual' => $actual,
            'suggestedGroups' => $row->suggested_groups,
            'actualGroups' => $row->actual_groups,
            'method' => $row->forecast_method,
            'methodLabel' => self::methodLabel($row->forecast_method),
            'error' => $error,
            'out' => $actual > 0 ? abs($projected - $actual) * 100 > $withinPercent * $actual : $projected > 0,
            'noHistory' => in_array($row->forecast_method, [ForecastMethod::DepartmentFallback->value, ForecastMethod::NoHistory->value], true),
            'counts' => $actual > 0 || $projected > 0,
        ];
    }

    /**
     * Para ordenar de mayor a menor error: proyectada sin alumnos arriba (por lo proyectado), luego el error relativo y
     * al final las que no cuentan.
     *
     * @param  array{projected: int, actual: int, error: ?float, counts: bool}  $subject
     * @return array{int, float}
     */
    private function errorRank(array $subject): array
    {
        return match (true) {
            ! $subject['counts'] => [0, 0.0],
            $subject['error'] === null => [2, (float) $subject['projected']],
            default => [1, abs($subject['error'])],
        };
    }
}
