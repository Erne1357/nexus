<?php

use App\Modules\AcademicLoad\Queries\RoomLoansQuery;

/*
| La regla de cobertura de HC-10, una sola para el validador, la sincronía de préstamos y la sugerencia de salones:
| qué partes de un intervalo no cubren los préstamos (varios contiguos o encimados cubren juntos).
*/

it('HC-10: lo que no cubren los intervalos, en orden, aunque lleguen desordenados', function () {
    expect(RoomLoansQuery::uncovered([[600, 660], [540, 570]], 540, 720))->toBe([[570, 600], [660, 720]]);
});

it('HC-10: varios intervalos contiguos o encimados cubren juntos', function () {
    expect(RoomLoansQuery::uncovered([[540, 600], [600, 660], [630, 720]], 540, 720))->toBe([])
        ->and(RoomLoansQuery::uncovered([[480, 800]], 540, 720))->toBe([]);
});

it('HC-10: sin intervalos, o con intervalos que no tocan, todo queda descubierto', function () {
    expect(RoomLoansQuery::uncovered([], 540, 660))->toBe([[540, 660]])
        ->and(RoomLoansQuery::uncovered([[420, 540], [660, 720]], 540, 660))->toBe([[540, 660]]);
});
