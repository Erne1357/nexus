<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Demand\Enums\ForecastMethod;
use App\Modules\Demand\Models\DemandForecast;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

/*
| `demand_forecasts` (tarea 5.1; docs/dominio/modelo-de-datos.md, «Demand»): una fila por periodo, modalidad,
| campus, carrera y materia, con el campus nulo solo en una modalidad sin salones (como `proposal_items`, MS5).
| Los nulos cuentan como iguales en la unicidad. Conteos no negativos y tasas de 0 a 1 (CHECK).
*/

it('pertenece a su periodo, modalidad, campus, carrera y materia', function () {
    $forecast = DemandForecast::factory()->create();

    expect($forecast->term)->toBeInstanceOf(Term::class)
        ->and($forecast->modality)->toBeInstanceOf(Modality::class)
        ->and($forecast->campus)->toBeInstanceOf(Campus::class)
        ->and($forecast->career)->toBeInstanceOf(Career::class)
        ->and($forecast->subject)->toBeInstanceOf(Subject::class)
        ->and($forecast->method)->toBe(ForecastMethod::Formula)
        ->and($forecast->non_enrollment_rate)->toBeFloat()
        ->and($forecast->computed_at)->not->toBeNull();
});

it('no repite periodo, modalidad, campus, carrera y materia', function () {
    $forecast = DemandForecast::factory()->create();

    expect(fn () => DemandForecast::factory()->create($forecast->only(['term_id', 'modality_id', 'campus_id', 'career_id', 'subject_id'])))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('tampoco repite la fila sin campus de una modalidad sin salones', function () {
    $forecast = DemandForecast::factory()->withoutCampus()->create();

    expect(fn () => DemandForecast::factory()->withoutCampus()->create($forecast->only(['term_id', 'modality_id', 'career_id', 'subject_id'])))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('admite la misma materia en otro campus, otra carrera o sin campus', function () {
    $forecast = DemandForecast::factory()->create();
    $key = $forecast->only(['term_id', 'modality_id', 'career_id', 'subject_id']);

    DemandForecast::factory()->create([...$key, 'campus_id' => Campus::factory()->create()->id]);
    DemandForecast::factory()->withoutCampus()->create($key);
    DemandForecast::factory()->create([...$key, 'campus_id' => $forecast->campus_id, 'career_id' => Career::factory()->create()->id]);

    expect(DemandForecast::query()->count())->toBe(4);
});

it('rechaza conteos negativos y tasas fuera de 0 a 1', function (string $column, mixed $value) {
    expect(fn () => DemandForecast::factory()->create([$column => $value]))->toThrow(QueryException::class);
})->with([
    'elegibles' => ['eligible_students', -1],
    'cursando' => ['current_students', -1],
    'seriación en curso' => ['pending_prerequisite_students', -1],
    'nuevo ingreso' => ['new_students', -1],
    'repetidores' => ['expected_repeaters', -1],
    'esperados' => ['expected_students', -1],
    'grupos' => ['suggested_groups', -1],
    'periodos de historia' => ['history_terms_used', -1],
    'no inscripción arriba de 1' => ['non_enrollment_rate', 1.5],
    'reprobación negativa' => ['failure_rate', -0.1],
]);

it('guarda las tasas como nulas cuando no hay historia', function () {
    $forecast = DemandForecast::factory()->withoutHistory()->create();

    expect($forecast->fresh()->non_enrollment_rate)->toBeNull()
        ->and($forecast->fresh()->failure_rate)->toBeNull()
        ->and($forecast->method)->toBe(ForecastMethod::NoHistory)
        ->and($forecast->history_terms_used)->toBe(0);
});
