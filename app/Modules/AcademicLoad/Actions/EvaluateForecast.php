<?php

namespace App\Modules\AcademicLoad\Actions;

use App\Modules\Academic\Enums\SubjectKind;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\ForecastEvaluation;
use App\Modules\AcademicLoad\Models\ForecastEvaluationRow;
use App\Modules\AcademicLoad\Rules\ForecastAccuracy;
use App\Modules\Core\Models\Campus;
use App\Modules\Demand\Actions\RefreshDemandForecasts;
use App\Modules\Demand\Queries\Occupancy;
use App\Modules\Demand\Queries\TermOccupancyQuery;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Evalúa la proyección de demanda «como si» se hubiera corrido antes del periodo de la evaluación (tarea 5.9b; DM-20 a
 * DM-23 y DM-26). La corre RunForecastEvaluation; la evaluación ya está corriendo.
 *
 * 1. «Calculando la proyección» (0 → 40 %): RefreshDemandForecasts::compute(asIf: true), que no guarda nada y
 *    reconstruye a los elegibles con el kárdex hasta el periodo ordinario anterior (DM-10).
 * 2. «Comparando con el kárdex» (40 → 60 %): la ocupación real del periodo (TermOccupancyQuery, DM-02).
 * 3. «Guardando resultados» (60 → 100 %): por carrera, campus y materia, la unión de lo proyectado y lo real (DM-26):
 *    - `projected` son los esperados de Demand (con repetidores y, en primer semestre, el nuevo ingreso);
 *    - `baseline` y `eligible`, los elegibles (la regla de R1);
 *    - `suggested_groups` y `forecast_method`, los de Demand;
 *    - `actual_students`, la ocupación. En una modalidad sin salones, lo de todos los campus va a la columna sin campus;
 *    - una materia cursada sin fila proyectada cuenta con proyectado, elegibles y grupos en 0 y el método
 *      NOT_PROJECTED, salvo las que la propuesta nunca lleva (residencia, servicio social, complementarias y sin horas
 *      de clase; supuesto por confirmar);
 *    - `actual_groups` queda nulo: los grupos reales salen de `historical_groups` del Riel B
 *      (SubjectGroupHistoryQuery), que todavía no está en develop. Pendiente de un PR corto.
 *
 * Las filas se guardan carrera por carrera (por clave), cada una en su transacción con el avance y `totals` de lo ya
 * guardado, así la pantalla ve las carreras conforme terminan. Volver a correrla reemplaza las filas de cada carrera.
 *
 * `totals` (jsonb), con las métricas de ForecastAccuracy de cada grupo de filas:
 *
 *     {
 *       "overall": Métricas,
 *       "by_career": [{"career_id": uuid, ...Métricas, "by_campus": [{"campus_id": ?uuid, ...Métricas}]}],
 *       "by_campus": [{"campus_id": ?uuid, ...Métricas}],
 *       "by_department": [{"department_id": ?uuid, ...Métricas}],
 *       "careers": {"done": int, "total": int}
 *     }
 *
 * Métricas = {weighted_error: ?float, baseline_error: ?float, mean_absolute_error: float, bias: float,
 * within_share: ?float, confidence: "strong"|"reference"|"unmeasured", rows: int}, con los flotantes redondeados a
 * cuatro decimales y la confianza calculada sin redondear. Las listas van por clave de la carrera, del campus y del
 * departamento, con el nulo al final; el departamento es el de la materia.
 */
final class EvaluateForecast
{
    public const string STAGE_COMPUTING = 'Calculando la proyección';

    public const string STAGE_COMPARING = 'Comparando con el kárdex';

    public const string STAGE_SAVING = 'Guardando resultados';

    /** El método de una fila que alguien cursó sin que la proyección la tuviera. */
    public const string NOT_PROJECTED = 'not_projected';

    private const int COMPUTED = 40;

    private const int COMPARED = 60;

    private const int DECIMALS = 4;

    private const int INSERT_CHUNK = 1000;

    /**
     * Los tipos de materia que la propuesta no lleva (los mismos que ForecastScopeQuery y StartCareerProposal).
     *
     * @var list<SubjectKind>
     */
    private const array OUTSIDE_PROPOSAL = [SubjectKind::Residencia, SubjectKind::ServicioSocial, SubjectKind::Complementaria];

    public function __construct(
        private readonly RefreshDemandForecasts $forecasts,
        private readonly TermOccupancyQuery $occupancy,
    ) {}

    public function run(ForecastEvaluation $evaluation): void
    {
        $term = $evaluation->term;
        $modality = $evaluation->modality;
        $campus = $evaluation->campus;

        $this->advance($evaluation, self::STAGE_COMPUTING, 0);
        $projected = $this->forecasts->compute($term, $modality, $campus, asIf: true);

        $this->advance($evaluation, self::STAGE_COMPARING, self::COMPUTED);
        $actual = $this->occupancy->forTerm($term->code, $modality, $campus);

        $this->advance($evaluation, self::STAGE_SAVING, self::COMPARED);
        $subjects = Subject::withTrashed()
            ->whereIn('id', array_unique([...array_column($projected, 'subject_id'), ...array_column($actual, 'subject_id')]))
            ->get(['id', 'department_id', 'kind', 'theory_hours', 'practice_hours'])
            ->keyBy('id');
        $rows = $this->cross($projected, $actual, $modality->profile->usesRooms, $subjects);
        $departments = $subjects->map(fn (Subject $subject): ?string => $subject->department_id)->all();
        $codes = $this->codes($rows, $departments);
        $byCareer = $this->groupBy($rows, fn (array $row): string => $row['career_id'], $codes['career']);
        $total = count($byCareer);
        $saved = [];
        $done = 0;

        foreach ($byCareer as $careerId => $careerRows) {
            $saved = [...$saved, ...$careerRows];
            $done++;

            DB::transaction(function () use ($evaluation, $careerId, $careerRows, $saved, $departments, $codes, $done, $total): void {
                ForecastEvaluationRow::query()->where('evaluation_id', $evaluation->getKey())->where('career_id', $careerId)->delete();
                $now = now();

                foreach (array_chunk($careerRows, self::INSERT_CHUNK) as $chunk) {
                    ForecastEvaluationRow::query()->insert(array_map(fn (array $row): array => [
                        ...$row,
                        'evaluation_id' => $evaluation->getKey(),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $chunk));
                }

                $evaluation->forceFill([
                    'progress' => self::COMPARED + intdiv((100 - self::COMPARED) * $done, $total),
                    'totals' => $this->totals($saved, $departments, $codes, $done, $total),
                ])->save();
            });
        }

        if ($total === 0) {
            $evaluation->forceFill(['progress' => 100, 'totals' => $this->totals([], $departments, $codes, 0, 0)])->save();
        }
    }

    private function advance(ForecastEvaluation $evaluation, string $stage, int $progress): void
    {
        $evaluation->forceFill(['stage' => $stage, 'progress' => $progress])->save();
    }

    /**
     * La unión de lo proyectado y lo real, por carrera, campus y materia (DM-26).
     *
     * @param  list<array<string, mixed>>  $projected  filas de RefreshDemandForecasts::compute()
     * @param  list<array{career_id: string, campus_id: ?string, subject_id: string, occupancy: Occupancy}>  $actual
     * @param  Collection<string, Subject>  $subjects
     * @return list<array{career_id: string, campus_id: ?string, subject_id: string, eligible: int, projected: int, suggested_groups: int, actual_students: int, actual_groups: null, baseline: int, forecast_method: string}>
     */
    private function cross(array $projected, array $actual, bool $usesRooms, Collection $subjects): array
    {
        $rows = [];

        foreach ($projected as $forecast) {
            $rows[$this->key($forecast['career_id'], $forecast['campus_id'], $forecast['subject_id'])] = [
                'career_id' => $forecast['career_id'],
                'campus_id' => $forecast['campus_id'],
                'subject_id' => $forecast['subject_id'],
                'eligible' => $forecast['eligible_students'],
                'projected' => $forecast['expected_students'],
                'suggested_groups' => $forecast['suggested_groups'],
                'actual_students' => 0,
                'actual_groups' => null,
                'baseline' => $forecast['eligible_students'],
                'forecast_method' => $forecast['method'],
            ];
        }

        foreach ($actual as $occupancy) {
            $campusId = $usesRooms ? $occupancy['campus_id'] : null;
            $key = $this->key($occupancy['career_id'], $campusId, $occupancy['subject_id']);

            if (! isset($rows[$key])) {
                if (! $this->inProposal($subjects->get($occupancy['subject_id']))) {
                    continue;
                }

                $rows[$key] = [
                    'career_id' => $occupancy['career_id'],
                    'campus_id' => $campusId,
                    'subject_id' => $occupancy['subject_id'],
                    'eligible' => 0,
                    'projected' => 0,
                    'suggested_groups' => 0,
                    'actual_students' => 0,
                    'actual_groups' => null,
                    'baseline' => 0,
                    'forecast_method' => self::NOT_PROJECTED,
                ];
            }

            $rows[$key]['actual_students'] += $occupancy['occupancy']->students;
        }

        return array_values($rows);
    }

    private function key(string $careerId, ?string $campusId, string $subjectId): string
    {
        return $careerId.'|'.($campusId ?? '').'|'.$subjectId;
    }

    /**
     * Si la materia entraría a una propuesta: tiene horas de clase y no es residencia, servicio social ni complementaria.
     */
    private function inProposal(?Subject $subject): bool
    {
        return $subject !== null
            && $subject->theory_hours + $subject->practice_hours > 0
            && ! in_array($subject->kind, self::OUTSIDE_PROPOSAL, true);
    }

    /**
     * Las claves de las carreras, los campus y los departamentos de las filas, para ordenar los grupos de `totals`.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, ?string>  $departments  materia → departamento
     * @return array{career: array<string, string>, campus: array<string, string>, department: array<string, string>}
     */
    private function codes(array $rows, array $departments): array
    {
        $codesOf = fn (string $model, array $ids): array => $model::withTrashed()->whereIn('id', array_values(array_unique(array_filter($ids))))->pluck('code', 'id')->all();

        return [
            'career' => $codesOf(Career::class, array_column($rows, 'career_id')),
            'campus' => $codesOf(Campus::class, array_column($rows, 'campus_id')),
            'department' => $codesOf(Department::class, array_values($departments)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, ?string>  $departments  materia → departamento
     * @param  array{career: array<string, string>, campus: array<string, string>, department: array<string, string>}  $codes
     * @return array<string, mixed>
     */
    private function totals(array $rows, array $departments, array $codes, int $done, int $total): array
    {
        $campusOf = fn (array $row): ?string => $row['campus_id'];
        $byCareer = [];

        foreach ($this->groupBy($rows, fn (array $row): string => $row['career_id'], $codes['career']) as $careerId => $careerRows) {
            $byCareer[] = [
                'career_id' => $careerId,
                ...$this->metrics($careerRows),
                'by_campus' => $this->listed('campus_id', $this->groupBy($careerRows, $campusOf, $codes['campus'])),
            ];
        }

        return [
            'overall' => $this->metrics($rows),
            'by_career' => $byCareer,
            'by_campus' => $this->listed('campus_id', $this->groupBy($rows, $campusOf, $codes['campus'])),
            'by_department' => $this->listed('department_id', $this->groupBy($rows, fn (array $row): ?string => $departments[$row['subject_id']] ?? null, $codes['department'])),
            'careers' => ['done' => $done, 'total' => $total],
        ];
    }

    /**
     * Agrupa las filas por la llave que da `$by` y ordena los grupos por la clave del registro, con el nulo al final. El
     * nulo se guarda con la llave '' porque un arreglo de PHP no admite null como llave.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): ?string  $by
     * @param  array<string, string>  $codes  id → clave
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupBy(array $rows, callable $by, array $codes): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[$by($row) ?? ''][] = $row;
        }

        uksort($grouped, fn (string $a, string $b): int => [$a === '' ? 1 : 0, $codes[$a] ?? '', $a] <=> [$b === '' ? 1 : 0, $codes[$b] ?? '', $b]);

        return $grouped;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $groups
     * @return list<array<string, mixed>>
     */
    private function listed(string $key, array $groups): array
    {
        $listed = [];

        foreach ($groups as $id => $groupRows) {
            $listed[] = [$key => $id === '' ? null : $id, ...$this->metrics($groupRows)];
        }

        return $listed;
    }

    /**
     * DM-20 a DM-23: las métricas de ForecastAccuracy de un grupo de filas.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{weighted_error: ?float, baseline_error: ?float, mean_absolute_error: float, bias: float, within_share: ?float, confidence: string, rows: int}
     */
    private function metrics(array $rows): array
    {
        $result = ForecastAccuracy::of(array_map(fn (array $row): array => [
            'projected' => $row['projected'],
            'actual' => $row['actual_students'],
            'baseline' => $row['baseline'],
        ], $rows));
        $round = fn (?float $value): ?float => $value === null ? null : round($value, self::DECIMALS);

        return [
            'weighted_error' => $round($result->weightedError),
            'baseline_error' => $round($result->baselineError),
            'mean_absolute_error' => round($result->meanAbsoluteError, self::DECIMALS),
            'bias' => round($result->bias, self::DECIMALS),
            'within_share' => $round($result->withinShare),
            'confidence' => $result->confidence()->value,
            'rows' => count($rows),
        ];
    }
}
