<?php

namespace App\Modules\Demand\Actions;

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Demand\Events\DemandForecastsComputed;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Demand\Queries\EligibilityProjectionQuery;
use App\Modules\Demand\Queries\ForecastScopeQuery;
use App\Modules\Demand\Queries\HistoricalRatesQuery;
use App\Modules\Demand\Rules\DemandForecaster;
use App\Modules\Demand\Rules\DemandSettings;
use App\Modules\Demand\Rules\ForecastInput;
use App\Modules\Demand\Rules\TermCode;
use Illuminate\Support\Facades\DB;

/**
 * Calcula y guarda la proyección de demanda de un periodo en una modalidad (tareas 5.3 y 5.4; reglas-de-negocio.md,
 * «Elegibilidad y demanda», DM-01 a DM-07). Por cada carrera ofrecida, cada columna de su propuesta y cada materia de
 * la propuesta (ForecastScopeQuery, la misma selección que la propuesta de carrera), junta:
 *
 * - los elegibles proyectados (también por tramo de distancia, GapBucket), los que lo son por seriación en curso y
 *   quienes cursan la materia (EligibilityProjectionQuery);
 * - las tasas de la ventana del mismo tipo y el nuevo ingreso histórico (HistoricalRatesQuery, `demand.history_terms`);
 * - y la fórmula (DemandForecaster) con los tamaños de `demand.target_group_size`.
 *
 * La columna sin campus de una modalidad sin salones suma los alumnos y las tasas de todos los campus. El nuevo ingreso
 * entra solo en las materias de primer semestre del plan vigente de la carrera (DM-05, confirmado el 17 de noviembre de
 * 2026). Se marcan para revisar la especialidad y la materia con elegibles en la ventana que nadie tomó en ningún
 * campus (DM-04). `non_enrollment_rate` guarda la no inscripción efectiva con los tramos (DM-03).
 *
 * compute() no escribe: sirve también «como si» se corriera durante el periodo ordinario anterior (la evaluación de la
 * 5.9). handle() reemplaza en una transacción las filas del periodo y la modalidad (o solo las de un campus) y, después
 * del commit, avisa con DemandForecastsComputed. Es una tabla derivada: no deja bitácora (decisión de modelado 5).
 */
final class RefreshDemandForecasts
{
    private const int INSERT_CHUNK = 1000;

    public function __construct(
        private readonly ForecastScopeQuery $scope,
        private readonly EligibilityProjectionQuery $projection,
        private readonly HistoricalRatesQuery $history,
    ) {}

    /**
     * Las filas de la proyección, listas para guardar (sin id ni fechas de la fila).
     *
     * @return list<array<string, mixed>>
     */
    public function compute(Term $term, Modality $modality, ?Campus $campus = null, bool $asIf = false): array
    {
        $settings = DemandSettings::fromSettings();
        $forecaster = new DemandForecaster($settings->groupSizes);
        $projection = $this->projection->forTerm($term->code, $modality, $asIf ? TermCode::previousRegular($term->code) : null);
        $rates = $this->history->forTerm($term->code, $modality, $settings->historyTerms);
        $computedAt = now();
        $rows = [];

        foreach ($this->scope->careers() as $career) {
            $columns = array_filter(
                $this->scope->campusesFor($modality, $career),
                fn (?Campus $column): bool => $campus === null || $column?->getKey() === $campus->getKey(),
            );

            if ($columns === []) {
                continue;
            }

            $subjects = $this->scope->subjectsFor($career);

            foreach ($columns as $column) {
                $campusId = $column?->getKey();
                $students = $campusId === null ? array_values($projection[$career->getKey()] ?? []) : [$projection[$career->getKey()][$campusId] ?? []];

                foreach ($subjects as $subject) {
                    $counts = ['eligible' => 0, 'pending' => 0, 'current' => 0];
                    $gaps = [];

                    foreach ($students as $bySubject) {
                        foreach ($counts as $key => $count) {
                            $counts[$key] = $count + ($bySubject[$subject->subject_id][$key] ?? 0);
                        }

                        foreach ($bySubject[$subject->subject_id]['gaps'] ?? [] as $bucket => $eligible) {
                            $gaps[$bucket] = ($gaps[$bucket] ?? 0) + $eligible;
                        }
                    }

                    $subjectRates = $rates->rateFor($subject->subject_id, $campusId, $subject->department_id);
                    $newStudents = $subject->current_plan_semester === 1 ? ($rates->newStudentsFor($career->getKey(), $campusId) ?? 0) : null;
                    $result = $forecaster->forecast(new ForecastInput(
                        eligibleStudents: $counts['eligible'],
                        pendingPrerequisiteStudents: $counts['pending'],
                        currentStudents: $counts['current'],
                        rates: $subjectRates,
                        newStudents: $newStudents,
                        needsLab: $subject->needs_lab,
                        eligibleByGap: $gaps,
                    ));

                    $rows[] = [
                        'term_id' => $term->getKey(),
                        'modality_id' => $modality->getKey(),
                        'campus_id' => $campusId,
                        'career_id' => $career->getKey(),
                        'subject_id' => $subject->subject_id,
                        'eligible_students' => $counts['eligible'],
                        'pending_prerequisite_students' => $counts['pending'],
                        'current_students' => $counts['current'],
                        'new_students' => $newStudents,
                        'non_enrollment_rate' => $result->nonEnrollmentRate === null ? null : round($result->nonEnrollmentRate, 4),
                        'failure_rate' => $subjectRates === null ? null : round($subjectRates->failure, 4),
                        'history_terms_used' => $subjectRates->termsUsed ?? 0,
                        'expected_repeaters' => $result->expectedRepeaters,
                        'expected_students' => $result->expectedStudents,
                        'suggested_groups' => $result->suggestedGroups,
                        'method' => $result->method->value,
                        'formula_version' => $result->formulaVersion,
                        'needs_review' => $subject->is_specialty || $rates->neverTaken($subject->subject_id),
                        'computed_at' => $computedAt,
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * Calcula y reemplaza la proyección guardada; devuelve cuántas filas quedaron.
     */
    public function handle(Term $term, Modality $modality, ?Campus $campus = null): int
    {
        $rows = $this->compute($term, $modality, $campus);
        $now = now();

        DB::transaction(function () use ($term, $modality, $campus, $rows, $now): void {
            DemandForecast::query()
                ->where('term_id', $term->getKey())
                ->where('modality_id', $modality->getKey())
                ->when($campus !== null, fn ($query) => $query->where('campus_id', $campus->getKey()))
                ->delete();

            foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                DemandForecast::query()->insert(array_map(fn (array $row): array => [...$row, 'created_at' => $now, 'updated_at' => $now], $chunk));
            }

            DemandForecastsComputed::dispatch(
                $term->getKey(),
                $modality->getKey(),
                $campus?->getKey(),
                array_values(array_unique(array_column($rows, 'career_id'))),
            );
        });

        return count($rows);
    }
}
