<?php

use App\Modules\Demand\Enums\ForecastMethod;
use App\Modules\Demand\Rules\DemandForecaster;
use App\Modules\Demand\Rules\ForecastInput;
use App\Modules\Demand\Rules\SubjectRates;
use App\Modules\Demand\Rules\SuggestedGroups;

/*
| Fórmula de la proyección (reglas-de-negocio.md, «Elegibilidad y demanda», R2; tarea 5.3):
| esperados = elegibles × (1 − tasa de no inscripción) + repetidores esperados (cursando × tasa de reprobación)
| + nuevo ingreso en primer semestre; grupos sugeridos = techo de esperados entre el tamaño objetivo (35, o 25 con
| laboratorio). Los elegibles ya traen a quienes cursan hoy la seriación, contados como si la aprobaran (confirmado
| el 16 de noviembre de 2026).
*/

function forecastInput(int $eligible = 0, int $current = 0, ?SubjectRates $rates = null, ?int $newStudents = null, bool $needsLab = false, int $pendingPrerequisite = 0): ForecastInput
{
    return new ForecastInput(
        eligibleStudents: $eligible,
        pendingPrerequisiteStudents: $pendingPrerequisite,
        currentStudents: $current,
        rates: $rates,
        newStudents: $newStudents,
        needsLab: $needsLab,
    );
}

it('resta a los elegibles la tasa de no inscripción y suma los repetidores esperados', function () {
    $result = (new DemandForecaster)->forecast(forecastInput(
        eligible: 100,
        current: 40,
        rates: new SubjectRates(nonEnrollment: 0.2, failure: 0.25, termsUsed: 4),
    ));

    expect($result->expectedRepeaters)->toBe(10)
        ->and($result->expectedStudents)->toBe(90)
        ->and($result->suggestedGroups)->toBe(3)
        ->and($result->method)->toBe(ForecastMethod::Formula)
        ->and($result->formulaVersion)->toBe(DemandForecaster::VERSION);
});

it('redondea al entero más cercano los inscritos y los repetidores por separado', function (int $eligible, int $current, float $nonEnrollment, float $failure, int $repeaters, int $expected) {
    $result = (new DemandForecaster)->forecast(forecastInput(
        eligible: $eligible,
        current: $current,
        rates: new SubjectRates(nonEnrollment: $nonEnrollment, failure: $failure, termsUsed: 4),
    ));

    expect($result->expectedRepeaters)->toBe($repeaters)
        ->and($result->expectedStudents)->toBe($expected);
})->with([
    'medio hacia arriba' => [5, 5, 0.5, 0.5, 3, 6],
    'abajo' => [10, 3, 0.33, 0.1, 0, 7],
    'arriba' => [10, 7, 0.26, 0.1, 1, 8],
    'todo se inscribe y nadie reprueba' => [33, 12, 0.0, 0.0, 0, 33],
    'nadie se inscribe' => [33, 12, 1.0, 0.0, 0, 0],
]);

it('sugiere grupos con el tamaño de laboratorio si la materia lo lleva', function () {
    $forecaster = new DemandForecaster(new SuggestedGroups(defaultSize: 35, labSize: 25));
    $rates = new SubjectRates(nonEnrollment: 0.0, failure: 0.0, termsUsed: 4);

    expect($forecaster->forecast(forecastInput(eligible: 60, rates: $rates, needsLab: false))->suggestedGroups)->toBe(2)
        ->and($forecaster->forecast(forecastInput(eligible: 60, rates: $rates, needsLab: true))->suggestedGroups)->toBe(3);
});

it('usa los tamaños objetivo que recibe', function () {
    $forecaster = new DemandForecaster(new SuggestedGroups(defaultSize: 40, labSize: 20));

    expect($forecaster->forecast(forecastInput(eligible: 80))->suggestedGroups)->toBe(2)
        ->and($forecaster->forecast(forecastInput(eligible: 81))->suggestedGroups)->toBe(3)
        ->and($forecaster->forecast(forecastInput(eligible: 41, needsLab: true))->suggestedGroups)->toBe(3);
});

it('con cero elegibles, nadie cursando y sin nuevo ingreso no espera alumnos ni sugiere grupos', function () {
    $result = (new DemandForecaster)->forecast(forecastInput(rates: new SubjectRates(0.3, 0.2, 4)));

    expect($result->expectedStudents)->toBe(0)
        ->and($result->expectedRepeaters)->toBe(0)
        ->and($result->suggestedGroups)->toBe(0);
});

it('sin historia ni tasa del departamento espera a todos los elegibles y lo marca', function () {
    $result = (new DemandForecaster)->forecast(forecastInput(eligible: 37, current: 10));

    expect($result->expectedStudents)->toBe(37)
        ->and($result->expectedRepeaters)->toBe(0)
        ->and($result->suggestedGroups)->toBe(2)
        ->and($result->method)->toBe(ForecastMethod::NoHistory);
});

it('marca la tasa del departamento cuando la materia no tiene historia propia', function () {
    $result = (new DemandForecaster)->forecast(forecastInput(
        eligible: 50,
        rates: new SubjectRates(nonEnrollment: 0.1, failure: 0.2, termsUsed: 4, fromDepartment: true),
    ));

    expect($result->expectedStudents)->toBe(45)
        ->and($result->method)->toBe(ForecastMethod::DepartmentFallback);
});

it('en primer semestre suma el nuevo ingreso a los rezagados y lo marca', function () {
    $result = (new DemandForecaster)->forecast(forecastInput(
        eligible: 12,
        current: 20,
        rates: new SubjectRates(nonEnrollment: 0.5, failure: 0.3, termsUsed: 4),
        newStudents: 140,
    ));

    expect($result->expectedRepeaters)->toBe(6)
        ->and($result->expectedStudents)->toBe(152)
        ->and($result->suggestedGroups)->toBe(5)
        ->and($result->method)->toBe(ForecastMethod::FirstSemester);
});

it('quienes cursan la seriación ya vienen en los elegibles y no se suman otra vez', function () {
    $result = (new DemandForecaster)->forecast(forecastInput(
        eligible: 40,
        pendingPrerequisite: 25,
        rates: new SubjectRates(nonEnrollment: 0.0, failure: 0.0, termsUsed: 4),
    ));

    expect($result->expectedStudents)->toBe(40);
});

it('rechaza conteos negativos y tasas fuera de 0 a 1', function (Closure $build) {
    expect($build)->toThrow(InvalidArgumentException::class);
})->with([
    'elegibles negativos' => [fn () => forecastInput(eligible: -1)],
    'cursando negativos' => [fn () => forecastInput(current: -1)],
    'nuevo ingreso negativo' => [fn () => forecastInput(newStudents: -1)],
    'seriación en curso mayor que los elegibles' => [fn () => forecastInput(eligible: 3, pendingPrerequisite: 4)],
    'no inscripción mayor que 1' => [fn () => new SubjectRates(nonEnrollment: 1.2, failure: 0.0, termsUsed: 1)],
    'reprobación negativa' => [fn () => new SubjectRates(nonEnrollment: 0.0, failure: -0.1, termsUsed: 1)],
    'periodos negativos' => [fn () => new SubjectRates(nonEnrollment: 0.0, failure: 0.0, termsUsed: -1)],
]);
