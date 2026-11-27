<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\ForecastEvaluation;
use App\Modules\AcademicLoad\Models\ForecastEvaluationRow;
use Illuminate\Database\QueryException;

/*
| Restricciones de forecast_evaluation_rows (DM-20 a DM-25): conteos no negativos y unicidad con campus nulo.
*/

it('rechaza un conteo negativo', function (string $column) {
    ForecastEvaluationRow::factory()->create([$column => -1]);
})->with(['eligible', 'projected', 'suggested_groups', 'actual_students', 'actual_groups', 'baseline'])->throws(QueryException::class);

it('acepta actual_groups nulo y campus nulo', function () {
    $row = ForecastEvaluationRow::factory()->create(['actual_groups' => null, 'campus_id' => null]);

    expect($row->fresh()->campus_id)->toBeNull();
});

it('no repite una materia de una carrera sin campus en la misma evaluación', function () {
    $evaluation = ForecastEvaluation::factory()->create();
    $career = Career::factory()->create();
    $subject = Subject::factory()->create();
    $attributes = ['evaluation_id' => $evaluation->id, 'career_id' => $career->id, 'subject_id' => $subject->id, 'campus_id' => null];

    ForecastEvaluationRow::factory()->create($attributes);
    ForecastEvaluationRow::factory()->create($attributes);
})->throws(QueryException::class);
