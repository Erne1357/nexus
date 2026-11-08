<?php

use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Staffing\Rules\PositionTag;

/*
| La etiqueta corta de las plazas de un docente en el ranking: TC, 3/4, MT, «Asig. 10 h», «Hon.» y «Apoyo 36 h»,
| en el orden de los tipos y unidas con «+».
*/

it('nombra cada tipo de plaza con su etiqueta corta', function (PositionKind $kind, int $hours, string $tag) {
    expect(PositionTag::of([['kind' => $kind, 'base_hours' => $hours]]))->toBe($tag);
})->with([
    'tiempo completo' => [PositionKind::TiempoCompleto, 40, 'TC'],
    'tres cuartos' => [PositionKind::TresCuartos, 30, '3/4'],
    'medio tiempo' => [PositionKind::MedioTiempo, 20, 'MT'],
    'asignatura' => [PositionKind::Asignatura, 10, 'Asig. 10 h'],
    'honorarios' => [PositionKind::Honorarios, 0, 'Hon.'],
    'apoyo' => [PositionKind::Apoyo, 36, 'Apoyo 36 h'],
]);

it('junta varias plazas en el orden de los tipos y suma las horas de asignatura', function () {
    expect(PositionTag::of([
        ['kind' => PositionKind::Asignatura, 'base_hours' => 4],
        ['kind' => PositionKind::TiempoCompleto, 'base_hours' => 40],
        ['kind' => PositionKind::Asignatura, 'base_hours' => 6],
    ]))->toBe('TC + Asig. 10 h');
});

it('sin plazas vigentes dice «Sin plaza»', function () {
    expect(PositionTag::of([]))->toBe('Sin plaza');
});
