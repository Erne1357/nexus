<?php

namespace App\Modules\AcademicLoad\Actions;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\ForecastEvaluation;
use App\Modules\AcademicLoad\Models\ForecastEvaluationRow;
use App\Modules\AcademicLoad\Queries\SubjectGroupHistoryQuery;
use App\Modules\AcademicLoad\Queries\TermGroupsQuery;
use App\Modules\AcademicLoad\Queries\TermKardexQuery;
use App\Modules\AcademicLoad\Rules\ForecastAccuracy;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Demand\Actions\RefreshDemandForecasts;
use App\Modules\Demand\Queries\ForecastScopeQuery;
use App\Modules\Demand\Queries\Occupancy;
use App\Modules\Demand\Queries\TermOccupancyQuery;
use Illuminate\Support\Facades\DB;

/**
 * Evalúa la proyección de demanda «como si» se hubiera corrido antes del periodo de la evaluación (tarea 5.9b; DM-20 a
 * DM-23 y DM-27). La corre RunForecastEvaluation; la evaluación ya está corriendo.
 *
 * 1. «Calculando la proyección» (0 → 40 %): RefreshDemandForecasts::compute(asIf: true), que no guarda nada y
 *    reconstruye a los elegibles con el kárdex hasta el periodo ordinario anterior (DM-10).
 * 2. «Comparando con el kárdex» (40 → 60 %): la ocupación real del periodo (TermOccupancyQuery, DM-02).
 * 3. «Guardando resultados» (60 → 100 %): por carrera, campus y materia, solo dentro del alcance de la proyección, el
 *    mismo de la propuesta (ForecastScopeQuery, HC-13; DM-27): carreras con oferta activa, los campus de su oferta (la
 *    columna sin campus en una modalidad sin salones) y las materias de la propuesta.
 *    - `projected` son los esperados de Demand (con repetidores y, en primer semestre, el nuevo ingreso);
 *    - `baseline` y `eligible`, los elegibles (la regla de R1);
 *    - `suggested_groups` y `forecast_method`, los de Demand;
 *    - `actual_students`, la ocupación. En una modalidad sin salones, lo de todos los campus va a la columna sin campus;
 *    - dentro del alcance, una materia cursada sin fila proyectada cuenta con proyectado, elegibles y grupos en 0 y el
 *      método NOT_PROJECTED (compute() cubre todo el alcance, así que es solo una defensa);
 *    - lo cursado fuera del alcance (otra carrera, un campus sin oferta, una materia fuera de la propuesta) no se guarda
 *      ni cuenta como error: va a `totals.out_of_scope`;
 *    - `actual_groups` son los grupos físicos que se abrieron (TermGroupsQuery, de `historical_groups`) de la materia en el
 *      campus de la fila (en una modalidad sin salones, de todos los campus). Son de la materia, no de la carrera: filas de
 *      distintas carreras con la misma materia y campus llevan el mismo número. Nulo si el periodo no tiene ningún
 *      renglón en el historial («sin dato»); 0 si lo tiene pero la materia no. Un periodo publicado a medias cuenta solo lo publicado en Nexus (supuesto por confirmar).
 *
 * Las filas se guardan carrera por carrera (por clave), cada una en su transacción con el avance, su entrada de
 * `by_career` y `careers.done`, así la pantalla ve las carreras conforme terminan. `overall`, `by_campus` y
 * `by_department` se calculan una vez, al final; mientras corre van nulos. Volver a correrla reemplaza las filas de cada
 * carrera.
 *
 * `totals` (jsonb), con las métricas de ForecastAccuracy de cada grupo de filas:
 *
 *     {
 *       "overall": Métricas|null,
 *       "by_career": [{"career_id": uuid, ...Métricas, "by_campus": [{"campus_id": ?uuid, ...Métricas}]}],
 *       "by_campus": [{"campus_id": ?uuid, ...Métricas}]|null,
 *       "by_department": [{"department_id": ?uuid, ...Métricas}]|null,
 *       "careers": {"done": int, "total": int},
 *       "out_of_scope": {"students": int, "subjects": int}
 *     }
 *
 * Métricas = {weighted_error: ?float, baseline_error: ?float, mean_absolute_error: float, bias: float,
 * within_share: ?float, confidence: "strong"|"reference"|"unmeasured", rows: int}, con los flotantes redondeados a
 * cuatro decimales y la confianza calculada sin redondear. Las listas van por clave de la carrera, del campus y del
 * departamento, con el nulo al final; el departamento es el de la materia. `out_of_scope.students` son los alumnos
 * distintos con kárdex del periodo cuya carrera no se proyecta o cuyo campus no tiene oferta de su carrera (de ellos no
 * se evalúa nada); `out_of_scope.subjects`, las materias distintas cursadas fuera del alcance por cualquier motivo.
 */
final class EvaluateForecast
{
    public const string STAGE_COMPUTING = 'Calculando la proyección';

    public const string STAGE_COMPARING = 'Comparando con el kárdex';

    public const string STAGE_SAVING = 'Guardando resultados';

    /** El método de una fila del alcance que alguien cursó sin que la proyección la tuviera. */
    public const string NOT_PROJECTED = 'not_projected';

    private const int COMPUTED = 40;

    private const int COMPARED = 60;

    private const int DECIMALS = 4;

    private const int INSERT_CHUNK = 1000;

    public function __construct(
        private readonly RefreshDemandForecasts $forecasts,
        private readonly TermOccupancyQuery $occupancy,
        private readonly ForecastScopeQuery $scope,
        private readonly TermKardexQuery $kardex,
        private readonly TermGroupsQuery $groups,
    ) {}

    public function run(ForecastEvaluation $evaluation): void
    {
        $term = $evaluation->term;
        $modality = $evaluation->modality;
        $campus = $evaluation->campus;

        $this->advance($evaluation, self::STAGE_COMPUTING, 0);
        $projected = $this->forecasts->compute($term, $modality, $campus, asIf: true);
        $usesRooms = $modality->profile->usesRooms;

        $this->advance($evaluation, self::STAGE_COMPARING, self::COMPUTED);
        $actual = $this->occupancy->forTerm($term->code, $modality, $campus);
        $scope = $this->inScope($modality, $campus);
        [$rows, $outOfScopeSubjects] = $this->cross($projected, $actual, $usesRooms, $scope['keys']);
        $rows = $this->withOpenedGroups($rows, $this->groups->forTerm($term->code, $modality), $usesRooms);
        $outOfScope = [
            'students' => $this->studentsOutOfScope($term->code, $modality, $campus, $usesRooms, $scope['columns']),
            'subjects' => count($outOfScopeSubjects),
        ];

        $this->advance($evaluation, self::STAGE_SAVING, self::COMPARED);
        $departments = Subject::withTrashed()->whereIn('id', array_values(array_unique(array_column($rows, 'subject_id'))))->pluck('department_id', 'id')->all();
        $codes = $this->codes($rows, $departments);
        $byCareer = $this->groupBy($rows, fn (array $row): string => $row['career_id'], $codes['career']);
        $total = count($byCareer);
        $careerTotals = [];
        $done = 0;

        foreach ($byCareer as $careerId => $careerRows) {
            $careerTotals[] = $this->careerTotals($careerId, $careerRows, $codes);
            $done++;

            DB::transaction(function () use ($evaluation, $careerId, $careerRows, $careerTotals, $outOfScope, $done, $total): void {
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
                    'progress' => min(99, self::COMPARED + intdiv((100 - self::COMPARED) * $done, $total)),
                    'totals' => [
                        'overall' => null,
                        'by_career' => $careerTotals,
                        'by_campus' => null,
                        'by_department' => null,
                        'careers' => ['done' => $done, 'total' => $total],
                        'out_of_scope' => $outOfScope,
                    ],
                ])->save();
            });
        }

        $campusOf = fn (array $row): ?string => $row['campus_id'];

        $evaluation->forceFill([
            'progress' => 100,
            'totals' => [
                'overall' => $this->metrics($rows),
                'by_career' => $careerTotals,
                'by_campus' => $this->listed('campus_id', $this->groupBy($rows, $campusOf, $codes['campus'])),
                'by_department' => $this->listed('department_id', $this->groupBy($rows, fn (array $row): ?string => $departments[$row['subject_id']] ?? null, $codes['department'])),
                'careers' => ['done' => $done, 'total' => $total],
                'out_of_scope' => $outOfScope,
            ],
        ])->save();
    }

    private function advance(ForecastEvaluation $evaluation, string $stage, int $progress): void
    {
        $evaluation->forceFill(['stage' => $stage, 'progress' => $progress])->save();
    }

    /**
     * El alcance de la proyección, como lo recorre compute() (DM-27): las llaves carrera|campus|materia y las columnas
     * carrera|campus, con el campus vacío en la columna sin campus.
     *
     * @return array{keys: array<string, true>, columns: array<string, true>}
     */
    private function inScope(Modality $modality, ?Campus $campus): array
    {
        $keys = [];
        $columns = [];

        foreach ($this->scope->careers() as $career) {
            $careerColumns = array_filter(
                $this->scope->campusesFor($modality, $career),
                fn (?Campus $column): bool => $campus === null || $column?->getKey() === $campus->getKey(),
            );

            if ($careerColumns === []) {
                continue;
            }

            $subjects = $this->scope->subjectsFor($career)->pluck('subject_id')->all();

            foreach ($careerColumns as $column) {
                $columns[$career->getKey().'|'.($column?->getKey() ?? '')] = true;

                foreach ($subjects as $subjectId) {
                    $keys[$this->key($career->getKey(), $column?->getKey(), $subjectId)] = true;
                }
            }
        }

        return ['keys' => $keys, 'columns' => $columns];
    }

    /**
     * Lo proyectado contra lo real, por carrera, campus y materia del alcance (DM-27), y las materias cursadas fuera.
     *
     * @param  list<array<string, mixed>>  $projected  filas de RefreshDemandForecasts::compute()
     * @param  list<array{career_id: string, campus_id: ?string, subject_id: string, occupancy: Occupancy}>  $actual
     * @param  array<string, true>  $scope  llaves carrera|campus|materia del alcance
     * @return array{0: list<array{career_id: string, campus_id: ?string, subject_id: string, eligible: int, projected: int, suggested_groups: int, actual_students: int, actual_groups: ?int, baseline: int, forecast_method: string}>, 1: array<string, true>}
     */
    private function cross(array $projected, array $actual, bool $usesRooms, array $scope): array
    {
        $rows = [];
        $outside = [];

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

            if (! isset($scope[$key])) {
                $outside[$occupancy['subject_id']] = true;

                continue;
            }

            $rows[$key] ??= [
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
            $rows[$key]['actual_students'] += $occupancy['occupancy']->students;
        }

        return [array_values($rows), $outside];
    }

    /**
     * Pone `actual_groups` (DM-27): los grupos de la materia en el campus de la fila, iguales para las carreras que la
     * comparten; todos los campus juntos en una modalidad sin salones; nulo si el periodo no tiene historial de grupos.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, array<string, int>>  $groups  materia → campus (o `none`) → grupos
     * @return list<array<string, mixed>>
     */
    private function withOpenedGroups(array $rows, array $groups, bool $usesRooms): array
    {
        if ($groups === []) {
            return $rows;
        }

        foreach ($rows as &$row) {
            $bySubject = $groups[$row['subject_id']] ?? [];
            $row['actual_groups'] = $usesRooms
                ? ($bySubject[$row['campus_id'] ?? SubjectGroupHistoryQuery::NO_CAMPUS] ?? 0)
                : array_sum($bySubject);
        }

        return $rows;
    }

    /**
     * Los alumnos distintos con kárdex del periodo cuya carrera no se proyecta o cuyo campus no tiene oferta de su
     * carrera (DM-27): de ellos no se evalúa nada.
     *
     * @param  array<string, true>  $columns  columnas carrera|campus del alcance
     */
    private function studentsOutOfScope(string $termCode, Modality $modality, ?Campus $campus, bool $usesRooms, array $columns): int
    {
        $students = 0;

        foreach ($this->kardex->studentsByCareerAndCampus($termCode, $modality, $campus) as $group) {
            if (! isset($columns[$group['career_id'].'|'.($usesRooms ? $group['campus_id'] : '')])) {
                $students += $group['students'];
            }
        }

        return $students;
    }

    private function key(string $careerId, ?string $campusId, string $subjectId): string
    {
        return $careerId.'|'.($campusId ?? '').'|'.$subjectId;
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
     * La entrada de `by_career` de una carrera: sus métricas y las de cada campus.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array{career: array<string, string>, campus: array<string, string>, department: array<string, string>}  $codes
     * @return array<string, mixed>
     */
    private function careerTotals(string $careerId, array $rows, array $codes): array
    {
        return [
            'career_id' => $careerId,
            ...$this->metrics($rows),
            'by_campus' => $this->listed('campus_id', $this->groupBy($rows, fn (array $row): ?string => $row['campus_id'], $codes['campus'])),
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
