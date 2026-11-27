<?php

use App\Modules\Demand\Enums\ForecastMethod;
use App\Modules\Demand\Enums\GapBucket;
use App\Modules\Demand\Rules\DemandForecaster;
use App\Modules\Demand\Rules\ForecastInput;
use App\Modules\Demand\Rules\SubjectRates;
use App\Modules\Demand\Rules\SuggestedGroups;

/*
| Fórmula de la proyección (reglas-de-negocio.md, «Elegibilidad y demanda», R2; tarea 5.3; versión v2, confirmada el
| 17 de noviembre de 2026):
| esperados = Σ por tramo de distancia (elegibles del tramo × (1 − no inscripción del tramo)) + repetidores esperados
| (cursando × reprobación × (1 − no inscripción)) + nuevo ingreso en primer semestre; grupos sugeridos = techo de
| esperados entre el tamaño objetivo (35, o 25 con laboratorio). Los elegibles ya traen a quienes cursan hoy la
| seriación, contados como si la aprobaran (confirmado el 16 de noviembre de 2026).
*/

/**
 * @param  array<string, int>  $eligibleByGap
 */
function forecastInput(int $eligible = 0, int $current = 0, ?SubjectRates $rates = null, ?int $newStudents = null, bool $needsLab = false, int $pendingPrerequisite = 0, array $eligibleByGap = []): ForecastInput
{
    return new ForecastInput(
        eligibleStudents: $eligible,
        pendingPrerequisiteStudents: $pendingPrerequisite,
        currentStudents: $current,
        rates: $rates,
        newStudents: $newStudents,
        needsLab: $needsLab,
        eligibleByGap: $eligibleByGap,
    );
}

it('DM-01: resta a los elegibles la tasa de no inscripción y suma los repetidores que vuelven a inscribirse', function () {
    $result = (new DemandForecaster)->forecast(forecastInput(
        eligible: 100,
        current: 40,
        rates: new SubjectRates(nonEnrollment: 0.2, failure: 0.25, termsUsed: 4),
    ));

    expect($result->expectedRepeaters)->toBe(8)
        ->and($result->expectedStudents)->toBe(88)
        ->and($result->suggestedGroups)->toBe(3)
        ->and($result->nonEnrollmentRate)->toEqualWithDelta(0.2, 0.000001)
        ->and($result->method)->toBe(ForecastMethod::Formula)
        ->and($result->formulaVersion)->toBe('v2');
});

it('DM-01: los repetidores esperados también pasan por la no inscripción de la materia', function () {
    $result = (new DemandForecaster)->forecast(forecastInput(
        current: 40,
        rates: new SubjectRates(nonEnrollment: 0.75, failure: 0.5, termsUsed: 4),
    ));

    expect($result->expectedRepeaters)->toBe(5)
        ->and($result->expectedStudents)->toBe(5);
});

it('DM-03: suma los elegibles de cada tramo de distancia por la no inscripción de su tramo', function () {
    $rates = new SubjectRates(nonEnrollment: 0.5, failure: 0.0, termsUsed: 4, nonEnrollmentByGap: [
        GapBucket::Same->value => 0.1,
        GapBucket::OneAhead->value => 0.8,
    ]);

    $result = (new DemandForecaster)->forecast(forecastInput(eligible: 60, rates: $rates, eligibleByGap: [
        GapBucket::Same->value => 20,
        GapBucket::OneAhead->value => 30,
        GapBucket::TwoAhead->value => 10,
    ]));

    expect($result->expectedStudents)->toBe(29)
        ->and($result->nonEnrollmentRate)->toEqualWithDelta(1 - 29 / 60, 0.00001)
        ->and($result->method)->toBe(ForecastMethod::Formula);
});

it('DM-03: un tramo sin tasa propia usa la de la materia en todos los tramos', function () {
    $rates = new SubjectRates(nonEnrollment: 0.25, failure: 0.0, termsUsed: 2, nonEnrollmentByGap: [GapBucket::Behind->value => 0.0]);

    $result = (new DemandForecaster)->forecast(forecastInput(eligible: 8, rates: $rates, eligibleByGap: [GapBucket::TwoAhead->value => 8]));

    expect($result->expectedStudents)->toBe(6)
        ->and($result->nonEnrollmentRate)->toBe(0.25);
});

it('DM-03: guarda la tasa efectiva; sin elegibles, la de la materia, y sin tasas, ninguna', function () {
    $rates = new SubjectRates(nonEnrollment: 0.4, failure: 0.0, termsUsed: 4, nonEnrollmentByGap: [GapBucket::Same->value => 0.1]);

    expect((new DemandForecaster)->forecast(forecastInput(current: 3, rates: $rates))->nonEnrollmentRate)->toBe(0.4)
        ->and((new DemandForecaster)->forecast(forecastInput(eligible: 7))->nonEnrollmentRate)->toBeNull();
});

it('DM-06: redondea al entero más cercano los inscritos y los repetidores por separado', function (int $eligible, int $current, float $nonEnrollment, float $failure, int $repeaters, int $expected) {
    $result = (new DemandForecaster)->forecast(forecastInput(
        eligible: $eligible,
        current: $current,
        rates: new SubjectRates(nonEnrollment: $nonEnrollment, failure: $failure, termsUsed: 4),
    ));

    expect($result->expectedRepeaters)->toBe($repeaters)
        ->and($result->expectedStudents)->toBe($expected);
})->with([
    'medio hacia arriba' => [5, 10, 0.5, 0.5, 3, 6],
    'abajo' => [10, 3, 0.33, 0.1, 0, 7],
    'arriba' => [10, 7, 0.26, 0.1, 1, 8],
    'todo se inscribe y nadie reprueba' => [33, 12, 0.0, 0.0, 0, 33],
    'nadie se inscribe, tampoco los repetidores' => [33, 12, 1.0, 0.5, 0, 0],
]);

it('DM-07: sugiere grupos con el tamaño de laboratorio si la materia lo lleva', function () {
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

it('DM-04: sin historia ni tasa del departamento espera a todos los elegibles y lo marca', function () {
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

it('DM-05: en primer semestre suma el nuevo ingreso a los rezagados y lo marca', function () {
    $result = (new DemandForecaster)->forecast(forecastInput(
        eligible: 12,
        current: 20,
        rates: new SubjectRates(nonEnrollment: 0.5, failure: 0.3, termsUsed: 4),
        newStudents: 140,
    ));

    expect($result->expectedRepeaters)->toBe(3)
        ->and($result->expectedStudents)->toBe(149)
        ->and($result->suggestedGroups)->toBe(5)
        ->and($result->method)->toBe(ForecastMethod::FirstSemester);
});

it('DM-01: quienes cursan la seriación ya vienen en los elegibles y no se suman otra vez', function () {
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
    'tramos que no suman los elegibles' => [fn () => forecastInput(eligible: 5, eligibleByGap: [GapBucket::Same->value => 4])],
    'tramo negativo' => [fn () => forecastInput(eligible: 0, eligibleByGap: [GapBucket::Same->value => 1, GapBucket::Behind->value => -1])],
    'tramo desconocido' => [fn () => forecastInput(eligible: 2, eligibleByGap: ['lejos' => 2])],
    'no inscripción de un tramo mayor que 1' => [fn () => new SubjectRates(nonEnrollment: 0.5, failure: 0.0, termsUsed: 1, nonEnrollmentByGap: [GapBucket::Same->value => 1.5])],
    'no inscripción de un tramo desconocido' => [fn () => new SubjectRates(nonEnrollment: 0.5, failure: 0.0, termsUsed: 1, nonEnrollmentByGap: ['lejos' => 0.5])],
    'no inscripción mayor que 1' => [fn () => new SubjectRates(nonEnrollment: 1.2, failure: 0.0, termsUsed: 1)],
    'reprobación negativa' => [fn () => new SubjectRates(nonEnrollment: 0.0, failure: -0.1, termsUsed: 1)],
    'periodos negativos' => [fn () => new SubjectRates(nonEnrollment: 0.0, failure: 0.0, termsUsed: -1)],
]);
