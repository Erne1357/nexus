<?php

use App\Modules\Academic\Models\Career;
use App\Modules\AcademicLoad\Enums\ForecastConfidence;
use App\Modules\AcademicLoad\Models\ForecastEvaluation;
use App\Modules\AcademicLoad\Models\ForecastEvaluationRow;
use App\Modules\AcademicLoad\Queries\ForecastConfidenceQuery;
use App\Modules\Core\Models\Campus;
use Carbon\CarbonImmutable;

/*
| ForecastConfidenceQuery (DM-22): el rótulo de confianza de una carrera sale de la última evaluación lista de la
| modalidad.
*/

beforeEach(function () {
    $this->career = Career::factory()->create();
    $this->campus = Campus::factory()->create();
    $this->modality = modalityOf('PRES');
});

/**
 * Una fila de la carrera con lo proyectado y lo real dados.
 */
function confidenceRow(ForecastEvaluation $evaluation, Career $career, Campus $campus, int $projected, int $actual): ForecastEvaluationRow
{
    return ForecastEvaluationRow::factory()->create([
        'evaluation_id' => $evaluation->id,
        'career_id' => $career->id,
        'campus_id' => $campus->id,
        'projected' => $projected,
        'actual_students' => $actual,
        'baseline' => $actual,
    ]);
}

it('DM-22: una carrera sin filas queda sin medir', function () {
    ForecastEvaluation::factory()->create(['modality_id' => $this->modality->id]);

    expect(app(ForecastConfidenceQuery::class)->forCareer($this->career, $this->modality))->toBe(ForecastConfidence::Unmeasured);
});

it('DM-22: sin ninguna evaluación queda sin medir', function () {
    expect(app(ForecastConfidenceQuery::class)->forCareer($this->career, $this->modality))->toBe(ForecastConfidence::Unmeasured);
});

it('DM-22: toma la última evaluación lista, no una fallida más reciente', function () {
    $old = ForecastEvaluation::factory()->create(['modality_id' => $this->modality->id, 'finished_at' => CarbonImmutable::now()->subDays(2)]);
    $newer = ForecastEvaluation::factory()->create(['modality_id' => $this->modality->id, 'finished_at' => CarbonImmutable::now()->subDay()]);
    $failed = ForecastEvaluation::factory()->failed()->create(['modality_id' => $this->modality->id, 'finished_at' => CarbonImmutable::now()]);
    confidenceRow($old, $this->career, $this->campus, 50, 100);
    confidenceRow($newer, $this->career, $this->campus, 100, 100);
    confidenceRow($failed, $this->career, $this->campus, 0, 100);

    expect(app(ForecastConfidenceQuery::class)->forCareer($this->career, $this->modality))->toBe(ForecastConfidence::Strong);
});

it('DM-22: con error alto es referencia, y solo cuentan las filas de la carrera', function () {
    $evaluation = ForecastEvaluation::factory()->create(['modality_id' => $this->modality->id]);
    confidenceRow($evaluation, $this->career, $this->campus, 60, 100);
    confidenceRow($evaluation, Career::factory()->create(), $this->campus, 100, 100);

    expect(app(ForecastConfidenceQuery::class)->forCareer($this->career, $this->modality))->toBe(ForecastConfidence::Reference);
});

it('DM-22: con campus mide solo las filas de ese campus', function () {
    $other = Campus::factory()->create();
    $evaluation = ForecastEvaluation::factory()->create(['modality_id' => $this->modality->id]);
    confidenceRow($evaluation, $this->career, $this->campus, 100, 100);
    confidenceRow($evaluation, $this->career, $other, 10, 100);

    $query = app(ForecastConfidenceQuery::class);

    expect($query->forCareer($this->career, $this->modality, $this->campus))->toBe(ForecastConfidence::Strong)
        ->and($query->forCareer($this->career, $this->modality, $other))->toBe(ForecastConfidence::Reference)
        ->and($query->forCareer($this->career, $this->modality))->toBe(ForecastConfidence::Reference);
});
