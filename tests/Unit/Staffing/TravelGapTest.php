<?php

use App\Modules\Staffing\Rules\TravelGap;

/*
| SC-09 (reglas-de-negocio.md), como lo muestra «Mi carga»: entre dos sesiones seguidas del mismo día en campus distintos,
| el docente tiene al menos `staffing.min_campus_travel_minutes` (60 por omisión); con menos, es un aviso. Nunca bloquea.
*/

it('SC-09: entre campus distintos da los minutos libres y avisa si son menos del mínimo', function (int $end, int $start, int $free, bool $short) {
    expect((new TravelGap(60))->between('C2', $end, 'C1', $start))->toBe(['free_minutes' => $free, 'short' => $short]);
})->with([
    'pegadas: 0 min' => [600, 600, 0, true],
    'media hora' => [600, 630, 30, true],
    'un minuto antes del mínimo' => [600, 659, 59, true],
    'justo el mínimo' => [600, 660, 60, false],
    'tres horas' => [600, 780, 180, false],
]);

it('SC-09: en el mismo campus no hay traslado', function () {
    expect((new TravelGap(60))->between('C1', 600, 'C1', 600))->toBeNull();
});

it('SC-09: si se empalman no es un aviso de traslado (es un choque, HC-01)', function () {
    expect((new TravelGap(60))->between('C2', 660, 'C1', 600))->toBe(['free_minutes' => -60, 'short' => false]);
});

it('SC-09: el mínimo sale del parámetro; uno que falta o no es un entero de 0 en adelante usa 60', function (mixed $value, int $expected) {
    expect(TravelGap::fromValue($value)->minimumMinutes)->toBe($expected);
})->with([
    'noventa' => [90, 90],
    'cero' => [0, 0],
    'falta' => [null, 60],
    'negativo' => [-5, 60],
    'texto' => ['90', 60],
]);
