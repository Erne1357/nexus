<?php

use App\Modules\AcademicLoad\Enums\ForecastConfidence;
use App\Modules\AcademicLoad\Rules\ForecastAccuracy;

/*
| ForecastAccuracy (DM-20 a DM-23): el error ponderado por alumnos de la proyección contra lo que se cursó.
*/

/**
 * @param  list<int>  $projected
 * @param  list<int>  $actual
 * @param  list<int>  $baseline
 * @return list<array{projected: int, actual: int, baseline: int}>
 */
function accuracyRows(array $projected, array $actual, array $baseline): array
{
    return array_map(fn (int $p, int $r, int $b): array => ['projected' => $p, 'actual' => $r, 'baseline' => $b], $projected, $actual, $baseline);
}

it('DM-20: pondera el error por los alumnos reales y lo compara con la línea base', function () {
    $result = ForecastAccuracy::of(accuracyRows([30, 50, 0, 12], [25, 50, 0, 0], [40, 60, 0, 10]));

    expect($result->weightedError)->toEqualWithDelta(17 / 75, 1e-9)
        ->and($result->baselineError)->toEqualWithDelta(35 / 75, 1e-9)
        ->and($result->confidence())->toBe(ForecastConfidence::Reference);
});

it('DM-21: una materia con real 0 y proyectado 0 no cuenta, y con real 0 y proyectado mayor suma error', function () {
    $result = ForecastAccuracy::of(accuracyRows([30, 50, 0, 12], [25, 50, 0, 0], [40, 60, 0, 10]));

    // n = 3 (la materia 0/0 no cuenta): (5 + 0 + 12) / 3 de error absoluto y el mismo sesgo, todo por encima.
    expect($result->meanAbsoluteError)->toEqualWithDelta(17 / 3, 1e-9)
        ->and($result->bias)->toEqualWithDelta(17 / 3, 1e-9);
});

it('DM-23: la proporción dentro del 15 % cuenta solo las materias con real mayor que cero', function () {
    $result = ForecastAccuracy::of(accuracyRows([30, 50, 0, 12], [25, 50, 0, 0], [40, 60, 0, 10]));

    // 30 contra 25 es 20 %: fuera; 50 contra 50: dentro. La materia con real 0 no entra.
    expect($result->withinShare)->toBe(0.5);
});

it('DM-20: con todo lo real en cero no hay error y el veredicto es sin medir, sin dividir entre cero', function () {
    $result = ForecastAccuracy::of(accuracyRows([10, 0], [0, 0], [5, 0]));

    expect($result->weightedError)->toBeNull()
        ->and($result->baselineError)->toBeNull()
        ->and($result->withinShare)->toBeNull()
        ->and($result->confidence())->toBe(ForecastConfidence::Unmeasured);
});

it('DM-20: sin materias tampoco truena', function () {
    $result = ForecastAccuracy::of([]);

    expect($result->weightedError)->toBeNull()
        ->and($result->meanAbsoluteError)->toBe(0.0)
        ->and($result->bias)->toBe(0.0)
        ->and($result->confidence())->toBe(ForecastConfidence::Unmeasured);
});

it('DM-22: un error ponderado de exactamente 15 % es sugerencia fuerte', function () {
    $result = ForecastAccuracy::of(accuracyRows([115], [100], [100]));

    expect($result->weightedError)->toBe(0.15)
        ->and($result->withinShare)->toBe(1.0)
        ->and($result->confidence())->toBe(ForecastConfidence::Strong);
});

it('DM-22: el sesgo es negativo si se proyectó de menos', function () {
    $result = ForecastAccuracy::of(accuracyRows([10, 40], [30, 40], [30, 40]));

    expect($result->bias)->toBe(-10.0)
        ->and($result->confidence())->toBe(ForecastConfidence::Reference);
});
