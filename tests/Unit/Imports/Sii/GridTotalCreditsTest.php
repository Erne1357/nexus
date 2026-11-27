<?php

use App\Modules\Imports\Sii\GridTotalCredits;

/*
| Créditos totales de una retícula del SII (`carreras.creditos_totales`), con la regla que comparten el paso `carreras` y
| la verificación: solo cuenta un entero mayor que cero, y de una retícula repetida quedan los mayores.
*/

it('solo cuenta un entero mayor que cero', function (string $value, ?int $expected) {
    expect(GridTotalCredits::parse($value))->toBe($expected);
})->with([
    'entero' => ['260', 260],
    'con espacios' => [' 260 ', 260],
    'con decimales' => ['260.0', 260],
    'cero' => ['0', null],
    'negativo' => ['-5', null],
    'vacío' => ['', null],
    'texto' => ['x', null],
]);

it('de una retícula repetida quedan los créditos mayores; sin ninguno válido, ninguno', function () {
    expect(GridTotalCredits::byGrid([
        ['carrera' => 'A', 'reticula' => '1', 'creditos_totales' => ''],
        ['carrera' => 'A', 'reticula' => '1', 'creditos_totales' => '260'],
        ['carrera' => 'A', 'reticula' => '3', 'creditos_totales' => '0'],
        ['carrera' => 'A', 'reticula' => '3', 'creditos_totales' => '260'],
        ['carrera' => 'A', 'reticula' => '3', 'creditos_totales' => '250'],
        ['carrera' => 'A', 'reticula' => '4', 'creditos_totales' => '0'],
    ]))->toBe(['A/1' => [260], 'A/3' => [250, 260]])
        ->and(GridTotalCredits::kept([250, 260]))->toBe(260);
});
