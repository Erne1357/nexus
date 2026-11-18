<?php

use App\Modules\Academic\Enums\SubjectKind;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\EvaluateForecast;
use App\Modules\AcademicLoad\Actions\RequestForecastEvaluation;
use App\Modules\AcademicLoad\Models\ForecastEvaluation;
use App\Modules\AcademicLoad\Models\ForecastEvaluationRow;
use App\Modules\AcademicLoad\Models\HistoricalGroup;
use App\Modules\AcademicLoad\Rules\ForecastAccuracy;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Demand\Actions\RefreshDemandForecasts;
use App\Modules\Demand\Queries\TermOccupancyQuery;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

/*
| Evaluar la proyección «como si» (tarea 5.9b; DM-27 y DM-28): Demand calcula lo que habría proyectado para 20261 con
| lo que se sabía durante 20253, y se compara, por carrera, campus y materia del alcance de la proyección (el de la
| propuesta), con lo que de verdad se cursó en 20261 (la ocupación del kárdex) y con la regla de R1 (los elegibles).
|
| Fixture, una carrera ofrecida en K1, plan con A (1.º), B (2.º, pide A) y C (3.º, pide B, de otro departamento):
| - Historia (egresados), 20251: 4 aprobaron A en 20243; B la aprueban dos, uno la reprueba y uno se da de baja.
| - Durante 20253: 6 cursan A (la aprueban), 3 cursan B (la aprueban) y 2 cursan A (la reprueban).
| - En 20261: 4 de los 6 llevan B, los 3 llevan C, los 2 repiten A y 5 de nuevo ingreso llevan A. Uno de los 3 lleva
|   además X, que no está en la retícula, y uno de los 6 lleva Residencia, que la propuesta no lleva: las dos quedan
|   fuera del alcance.
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['code' => '20261', 'name' => 'Ene-Jun 2026']);
    $this->k1 = Campus::factory()->create(['code' => 'K1']);
    $this->plan = StudyPlan::factory()->create(['code' => 'PL-EV']);
    $this->plan->career->update(['code' => 'ISX']);
    CareerCampus::factory()->create(['career_id' => $this->plan->career_id, 'campus_id' => $this->k1->id]);
    $this->basics = Department::factory()->create(['code' => 'DB']);
    $this->systems = Department::factory()->create(['code' => 'DS']);
    $this->a = Subject::factory()->create(['code' => 'EA-1', 'department_id' => $this->basics->id]);
    $this->b = Subject::factory()->create(['code' => 'EB-2', 'department_id' => $this->basics->id]);
    $this->c = Subject::factory()->create(['code' => 'EC-3', 'department_id' => $this->systems->id]);
    $this->x = Subject::factory()->create(['code' => 'EX-9', 'department_id' => $this->systems->id]);
    $this->residency = Subject::factory()->create(['code' => 'ER-0', 'department_id' => $this->systems->id, 'kind' => SubjectKind::Residencia]);
    placeInPlan($this->plan, $this->a);
    placeInPlan($this->plan, $this->b, 2);
    placeInPlan($this->plan, $this->c, 3);
    seriate($this->plan, $this->b, $this->a);
    seriate($this->plan, $this->c, $this->b);
});

/**
 * @param  list<array{0: Subject, 1: string, 2: KardexResult}>  $records
 */
function evaluationStudent(Campus $campus, array $records, StudentStatus $status = StudentStatus::Activo, ?Modality $modality = null): Student
{
    $student = Student::factory()->create([
        'study_plan_id' => test()->plan->id,
        'career_id' => test()->plan->career_id,
        'campus_id' => $campus->id,
        'modality_id' => ($modality ?? modalityOf('PRES'))->id,
        'status' => $status,
    ]);

    foreach ($records as [$subject, $term, $result]) {
        KardexRecord::factory()->for($student)->for($subject)->create(['term_code' => $term, 'result' => $result, 'grade' => null]);
    }

    return $student;
}

function evaluationScenario(?Modality $modality = null, ?Campus $campus = null): void
{
    $t = test();
    $campus ??= $t->k1;
    $ok = KardexResult::Aprobada;
    $taking = KardexResult::Cursando;

    foreach ([$ok, $ok, KardexResult::Reprobada, KardexResult::Baja] as $result) {
        evaluationStudent($campus, [[$t->a, '20243', $ok], [$t->b, '20251', $result]], StudentStatus::Egresado, $modality);
    }

    foreach (range(1, 6) as $i) {
        $records = [[$t->a, '20253', $ok]];
        $records = $i <= 4 ? [...$records, [$t->b, '20261', $taking]] : $records;
        evaluationStudent($campus, $i === 1 ? [...$records, [$t->residency, '20261', $taking]] : $records, modality: $modality);
    }

    foreach (range(1, 3) as $i) {
        $records = [[$t->a, '20243', $ok], [$t->b, '20253', $ok], [$t->c, '20261', $taking]];
        evaluationStudent($campus, $i === 1 ? [...$records, [$t->x, '20261', $taking]] : $records, modality: $modality);
    }

    foreach (range(1, 2) as $i) {
        evaluationStudent($campus, [[$t->a, '20253', KardexResult::Reprobada], [$t->a, '20261', $taking]], modality: $modality);
    }

    foreach (range(1, 5) as $i) {
        evaluationStudent($campus, [[$t->a, '20261', $taking]], modality: $modality);
    }
}

function runningEvaluation(?Modality $modality = null, ?Campus $campus = null): ForecastEvaluation
{
    return ForecastEvaluation::factory()->running()->create([
        'term_id' => test()->term->id,
        'modality_id' => ($modality ?? modalityOf('PRES'))->id,
        'campus_id' => $campus?->id,
    ]);
}

/**
 * @return array<string, ForecastEvaluationRow> clave de materia y de campus («EB-2@K1») → fila
 */
function evaluationRows(ForecastEvaluation $evaluation): array
{
    return $evaluation->rows()->with(['subject', 'campus'])->get()
        ->keyBy(fn (ForecastEvaluationRow $row): string => $row->subject->code.'@'.($row->campus->code ?? '-'))
        ->all();
}

it('DM-27: guarda una fila por carrera, campus y materia con proyectado, real y base de R1', function () {
    evaluationScenario();
    $evaluation = runningEvaluation();
    $projected = collect(app(RefreshDemandForecasts::class)->compute($this->term, modalityOf('PRES'), asIf: true))
        ->keyBy(fn (array $row): string => $row['subject_id']);
    $actual = collect(app(TermOccupancyQuery::class)->forTerm('20261', modalityOf('PRES')))
        ->mapWithKeys(fn (array $row): array => [$row['subject_id'] => $row['occupancy']->students]);

    app(EvaluateForecast::class)->run($evaluation);
    $rows = evaluationRows($evaluation);

    expect(array_keys($rows))->toEqualCanonicalizing(['EA-1@K1', 'EB-2@K1', 'EC-3@K1'])
        ->and(array_map(fn (ForecastEvaluationRow $row): int => $row->actual_students, $rows))
        ->toEqual(['EA-1@K1' => 7, 'EB-2@K1' => 4, 'EC-3@K1' => 3]);

    foreach ([$this->a, $this->b, $this->c] as $subject) {
        $forecast = $projected[$subject->id];

        expect($rows[$subject->code.'@K1'])
            ->career_id->toBe($this->plan->career_id)
            ->eligible->toBe($forecast['eligible_students'])
            ->projected->toBe($forecast['expected_students'])
            ->baseline->toBe($forecast['eligible_students'])
            ->suggested_groups->toBe($forecast['suggested_groups'])
            ->forecast_method->toBe($forecast['method'])
            ->actual_students->toBe($actual[$subject->id])
            ->actual_groups->toBeNull();
    }

    expect($evaluation->fresh())
        ->progress->toBe(100)
        ->stage->toBe(EvaluateForecast::STAGE_SAVING)
        ->totals->out_of_scope->toEqual(['students' => 0, 'subjects' => 2]);
});

it('DM-27: actual_groups lleva los grupos físicos de la materia en el campus: nulo sin historial del periodo, 0 si la materia no tiene', function () {
    evaluationScenario();
    $other = Campus::factory()->create(['code' => 'K2']);
    $evaluation = runningEvaluation();
    app(EvaluateForecast::class)->run($evaluation);

    expect(collect(evaluationRows($evaluation))->pluck('actual_groups')->unique()->all())->toBe([null]);

    $parent = HistoricalGroup::factory()->fromSii()->create(['term_code' => '20261', 'subject_id' => $this->a->id, 'campus_id' => $this->k1->id, 'group_code' => 'A']);
    HistoricalGroup::factory()->fromSii()->create(['term_code' => '20261', 'subject_id' => $this->a->id, 'campus_id' => $this->k1->id, 'group_code' => 'A2', 'parallel_of' => $parent->id]);
    HistoricalGroup::factory()->fromSii()->create(['term_code' => '20261', 'subject_id' => $this->a->id, 'campus_id' => $this->k1->id, 'group_code' => 'B']);
    HistoricalGroup::factory()->fromSii()->create(['term_code' => '20261', 'subject_id' => $this->b->id, 'campus_id' => $other->id, 'group_code' => 'A']);

    app(EvaluateForecast::class)->run($evaluation);
    $rows = evaluationRows($evaluation);

    expect($rows['EA-1@K1']->actual_groups)->toBe(2)
        ->and($rows['EB-2@K1']->actual_groups)->toBe(0);
});

it('DM-27: dos carreras con la misma materia y campus llevan el mismo número de grupos abiertos', function () {
    evaluationScenario();
    $second = StudyPlan::factory()->create(['code' => 'PL-E2']);
    $second->career->update(['code' => 'ISY']);
    CareerCampus::factory()->create(['career_id' => $second->career_id, 'campus_id' => $this->k1->id]);
    placeInPlan($second, $this->a);
    HistoricalGroup::factory()->fromSii()->count(3)->create(['term_code' => '20261', 'subject_id' => $this->a->id, 'campus_id' => $this->k1->id]);
    $evaluation = runningEvaluation();

    app(EvaluateForecast::class)->run($evaluation);
    $rows = $evaluation->rows()->where('subject_id', $this->a->id)->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('actual_groups')->all())->toBe([3, 3]);
});

it('DM-27: lo cursado en un campus sin oferta o en una carrera que no se proyecta no genera filas: queda en out_of_scope', function () {
    evaluationScenario();
    $k2 = Campus::factory()->create(['code' => 'K2']);
    CareerCampus::factory()->inactive()->create(['career_id' => $this->plan->career_id, 'campus_id' => $k2->id]);
    $taking = KardexResult::Cursando;

    foreach (range(1, 3) as $i) {
        evaluationStudent($k2, [[$this->a, '20253', KardexResult::Aprobada], [$this->b, '20261', $taking], [$this->c, '20261', $taking]]);
    }

    $unoffered = StudyPlan::factory()->create();
    placeInPlan($unoffered, $this->a);

    foreach (range(1, 2) as $i) {
        $student = Student::factory()->create(['study_plan_id' => $unoffered->id, 'campus_id' => $this->k1->id]);
        KardexRecord::factory()->for($student)->for($this->a)->create(['term_code' => '20261', 'result' => $taking, 'grade' => null]);
    }

    $evaluation = runningEvaluation();

    app(EvaluateForecast::class)->run($evaluation);
    $totals = $evaluation->fresh()->totals;

    expect(array_keys(evaluationRows($evaluation)))->toEqualCanonicalizing(['EA-1@K1', 'EB-2@K1', 'EC-3@K1'])
        ->and($evaluation->rows()->where('career_id', $unoffered->career_id)->exists())->toBeFalse()
        ->and($totals['overall']['rows'])->toBe(3)
        ->and(array_column($totals['by_campus'], 'campus_id'))->toBe([$this->k1->id])
        ->and($totals['out_of_scope'])->toEqual(['students' => 5, 'subjects' => 5]);
});

it('calcula el error ponderado y la confianza de cada carrera en totals', function () {
    evaluationScenario();
    $other = StudyPlan::factory()->create();
    $other->career->update(['code' => 'ISY']);
    placeInPlan($other, $this->a);
    CareerCampus::factory()->create(['career_id' => $other->career_id, 'campus_id' => $this->k1->id]);

    foreach (range(1, 2) as $i) {
        $student = Student::factory()->create(['study_plan_id' => $other->id, 'campus_id' => $this->k1->id]);
        KardexRecord::factory()->for($student)->for($this->a)->create(['term_code' => '20261', 'result' => KardexResult::Cursando, 'grade' => null]);
    }

    $evaluation = runningEvaluation();
    $saved = [];
    Event::listen('eloquent.saved: '.ForecastEvaluation::class, function (ForecastEvaluation $saving) use (&$saved): void {
        $saved[] = $saving->totals;
    });

    app(EvaluateForecast::class)->run($evaluation);
    $totals = $evaluation->fresh()->totals;
    $metrics = function (iterable $rows): array {
        $result = ForecastAccuracy::of(collect($rows)->map(fn (ForecastEvaluationRow $row): array => ['projected' => $row->projected, 'actual' => $row->actual_students, 'baseline' => $row->baseline]));

        return [
            'weighted_error' => round($result->weightedError, 4),
            'baseline_error' => round($result->baselineError, 4),
            'confidence' => $result->confidence()->value,
        ];
    };
    $rows = $evaluation->rows()->with('subject')->get();
    $isx = $rows->where('career_id', $this->plan->career_id);
    $career = $totals['by_career'][0];
    $firstCareer = collect($saved)->first(fn (?array $partial): bool => ($partial['careers']['done'] ?? 0) === 1);

    expect($totals['overall'])->toMatchArray($metrics($rows))
        ->and($totals['overall']['rows'])->toBe(4)
        ->and(array_column($totals['by_career'], 'career_id'))->toBe([$this->plan->career_id, $other->career_id])
        ->and($career)->toMatchArray(['career_id' => $this->plan->career_id, ...$metrics($isx)])
        ->and($career['by_campus'])->toHaveCount(1)
        ->and($career['by_campus'][0])->toMatchArray(['campus_id' => $this->k1->id, ...$metrics($isx)])
        ->and($totals['by_campus'])->toHaveCount(1)
        ->and($totals['by_campus'][0])->toMatchArray(['campus_id' => $this->k1->id, ...$metrics($rows)])
        ->and(array_column($totals['by_department'], 'department_id'))->toBe([$this->basics->id, $this->systems->id])
        ->and($totals['by_department'][0])->toMatchArray($metrics($rows->filter(fn (ForecastEvaluationRow $row): bool => $row->subject->department_id === $this->basics->id)))
        ->and($totals['by_department'][1])->toMatchArray($metrics($rows->filter(fn (ForecastEvaluationRow $row): bool => $row->subject->department_id === $this->systems->id)))
        ->and($totals['careers'])->toBe(['done' => 2, 'total' => 2])
        ->and(array_keys($totals))->toEqualCanonicalizing(['overall', 'by_career', 'by_campus', 'by_department', 'careers', 'out_of_scope'])
        ->and(array_keys($totals['overall']))->toEqualCanonicalizing(['weighted_error', 'baseline_error', 'mean_absolute_error', 'bias', 'within_share', 'confidence', 'rows'])
        ->and($firstCareer)->toMatchArray(['overall' => null, 'by_campus' => null, 'by_department' => null, 'careers' => ['done' => 1, 'total' => 2]])
        ->and($firstCareer['by_career'])->toEqual([$career]);
});

it('DM-27: en una modalidad sin salones compara una sola columna sin campus con lo cursado en todos los campus', function () {
    $online = Modality::factory()->create(['code' => 'M902', 'settings' => ['uses_rooms' => false]]);
    evaluationScenario($online);
    evaluationStudent(Campus::factory()->create(), [[$this->a, '20261', KardexResult::Cursando]], modality: $online);
    $evaluation = runningEvaluation($online);

    app(EvaluateForecast::class)->run($evaluation);
    $rows = evaluationRows($evaluation);

    expect(array_keys($rows))->toEqualCanonicalizing(['EA-1@-', 'EB-2@-', 'EC-3@-'])
        ->and($rows['EA-1@-']->actual_students)->toBe(8)
        ->and($evaluation->fresh()->totals['by_campus'][0]['campus_id'])->toBeNull();
});

it('DM-28: no crea la evaluación si el periodo no tiene kárdex', function () {
    Queue::fake();
    $empty = Term::factory()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    evaluationScenario();

    expect(fn () => app(RequestForecastEvaluation::class)->handle($empty, modalityOf('PRES'), null, User::factory()->create()))
        ->toThrow(ValidationException::class, 'No hay kárdex de Ene-Jun 2027: no hay con qué comparar.')
        ->and(fn () => app(RequestForecastEvaluation::class)->handle($this->term, modalityOf('PRES'), Campus::factory()->create(['name' => 'Campus Z']), User::factory()->create()))
        ->toThrow(ValidationException::class, 'No hay kárdex de Ene-Jun 2026 en Campus Z: no hay con qué comparar.')
        ->and(ForecastEvaluation::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('DM-28: no evalúa un verano ni un campus de una modalidad sin salones', function () {
    Queue::fake();
    $summer = Term::factory()->create(['code' => '20262', 'name' => 'Verano 2026']);
    $online = Modality::factory()->create(['name' => 'En línea de prueba', 'settings' => ['uses_rooms' => false]]);
    evaluationScenario();
    evaluationStudent($this->k1, [[$this->a, '20262', KardexResult::Cursando]]);
    evaluationStudent($this->k1, [[$this->a, '20261', KardexResult::Cursando]], modality: $online);

    expect(fn () => app(RequestForecastEvaluation::class)->handle($summer, modalityOf('PRES'), null, null))
        ->toThrow(ValidationException::class, 'La proyección no calcula cursos de verano: elige un periodo Ene-Jun o Ago-Dic.')
        ->and(fn () => app(RequestForecastEvaluation::class)->handle($this->term, $online, $this->k1, null))
        ->toThrow(ValidationException::class, 'En línea de prueba no usa salones: se evalúa con todos sus campus.')
        ->and(ForecastEvaluation::query()->count())->toBe(0);
});
