<?php

namespace App\Modules\Demand\Queries;

use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Modality;
use App\Modules\Demand\Rules\HistoricalRates;
use App\Modules\Demand\Rules\StandingCalculator;
use App\Modules\Demand\Rules\TermCode;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Queries\PlanGrid;
use App\Modules\Students\Queries\PlanGridQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * Cuenta, del kárdex, las tasas históricas de una modalidad para proyectar un periodo (tarea 5.2; reglas-de-negocio.md,
 * «Elegibilidad y demanda», DM-03 a DM-05). Mira los `$historyTerms` periodos del mismo tipo anteriores al objetivo
 * (`demand.history_terms`), así que sirve igual para la proyección de hoy que «como si» se corriera antes (tarea 5.9):
 * nada del objetivo ni de después entra.
 *
 * En cada periodo de la ventana:
 * - La población son los alumnos de la modalidad, sin borrar, con alguna fila del kárdex en ese periodo, con cualquier
 *   resultado (DM-10, supuesto por confirmar). Su campus y su plan son los de hoy.
 * - Los elegibles de cada uno salen de su kárdex anterior al periodo y de la retícula de su plan (StandingCalculator).
 * - La tomó quien la tiene ese periodo aprobada, reprobada o cursando; la baja no (DM-02).
 * - La reprobación cuenta a todos los que la terminaron con calificación ese periodo, elegibles o no.
 * - Entró en ese periodo quien no tiene kárdex de antes.
 *
 * Lee a los alumnos de 500 en 500, cada bloque con su kárdex hasta el periodo. Tarda: la corre el job de la proyección.
 */
final class HistoricalRatesQuery
{
    private const int CHUNK = 500;

    public function __construct(
        private readonly PlanGridQuery $grids,
        private readonly StandingCalculator $standings,
    ) {}

    public function forTerm(string $targetTermCode, Modality $modality, int $historyTerms): HistoricalRates
    {
        $bySubject = [];
        $intake = [];
        $populated = [];
        /** @var array<string, PlanGrid> $grids */
        $grids = [];

        foreach (TermCode::sameTypeBefore($targetTermCode, $historyTerms) as $term) {
            Student::query()
                ->where('modality_id', $modality->getKey())
                ->whereExists(fn (Builder $query) => $query->selectRaw('1')
                    ->from('kardex_records')
                    ->whereColumn('kardex_records.student_id', 'students.id')
                    ->where('kardex_records.term_code', $term)
                    ->whereNull('kardex_records.deleted_at'))
                ->select(['id', 'career_id', 'campus_id', 'study_plan_id'])
                ->chunkById(self::CHUNK, function (Collection $students) use ($term, &$bySubject, &$intake, &$populated, &$grids): void {
                    $kardex = KardexRecord::query()
                        ->whereIn('student_id', $students->modelKeys())
                        ->where(fn ($query) => $query->whereNull('term_code')->orWhere('term_code', '<=', $term))
                        ->toBase()
                        ->get(['student_id', 'subject_id', 'term_code', 'result'])
                        ->groupBy('student_id');

                    foreach ($students as $student) {
                        $records = $kardex->get($student->id, collect());
                        $campus = $student->campus_id;
                        $populated[$campus][$term] = true;
                        $took = [];
                        $earlier = false;

                        foreach ($records as $record) {
                            if ($record->term_code !== $term) {
                                $earlier = true;

                                continue;
                            }

                            if ($record->result === KardexResult::Baja->value) {
                                continue;
                            }

                            $took[$record->subject_id] = true;

                            if ($record->result !== KardexResult::Cursando->value) {
                                $counts = &self::counts($bySubject, $record->subject_id, $campus);
                                $counts['graded']++;
                                $counts['failed'] += $record->result === KardexResult::Reprobada->value ? 1 : 0;
                                unset($counts);
                            }
                        }

                        if (! $earlier) {
                            $intake[$student->career_id][$campus][$term] = ($intake[$student->career_id][$campus][$term] ?? 0) + 1;
                        }

                        $grid = $grids[$student->study_plan_id] ??= $this->grids->forPlan($student->study_plan_id);

                        if ($grid->credits === [] && $grid->unplaced === []) {
                            continue;
                        }

                        foreach ($this->standings->at($grid, $records, $term)->eligible as $subject) {
                            $counts = &self::counts($bySubject, $subject, $campus);
                            $counts['eligible']++;
                            $counts['took'] += isset($took[$subject]) ? 1 : 0;
                            $counts['terms'][$term] = true;
                            unset($counts);
                        }
                    }
                });
        }

        $departmentOf = $bySubject === [] ? [] : Subject::withTrashed()
            ->whereIn('id', array_keys($bySubject))
            ->pluck('department_id', 'id')
            ->all();

        return new HistoricalRates($bySubject, $departmentOf, $intake, $populated);
    }

    /**
     * Los conteos de una materia en un campus, nacidos en cero.
     *
     * @param  array<string, array<string, array{eligible: int, took: int, graded: int, failed: int, terms: array<string, true>}>>  $bySubject
     * @return array{eligible: int, took: int, graded: int, failed: int, terms: array<string, true>}
     */
    private static function &counts(array &$bySubject, string $subject, string $campus): array
    {
        $bySubject[$subject][$campus] ??= ['eligible' => 0, 'took' => 0, 'graded' => 0, 'failed' => 0, 'terms' => []];

        return $bySubject[$subject][$campus];
    }
}
