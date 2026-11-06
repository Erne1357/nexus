<?php

use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Imports\Sii\PlazaKeys;

/*
| Claves de plaza de `docentes2.claves_plazas`: `<prefijo> <categoría><horas>.<número>`, varias por renglón. La
| categoría da el tipo (supuesto por confirmar, sacado de los datos) y la asignatura trae sus horas en la clave.
| Claves sintéticas.
*/

it('reconoce el tipo de plaza por la categoría', function (string $field, PositionKind $kind, int $hours, string $category) {
    expect(PlazaKeys::parse($field))->toBe([['category' => $category, 'kind' => $kind, 'hours' => $hours]]);
})->with([
    'asignatura con sus horas' => ['1403 E352504.0100001', PositionKind::Asignatura, 4, 'E3525'],
    'asignatura de técnico docente' => ['1403 E350712.0100002', PositionKind::Asignatura, 12, 'E3507'],
    'medio tiempo' => ['1403 E361700.0100003', PositionKind::MedioTiempo, 20, 'E3617'],
    'tres cuartos' => ['1456 E371700.0100004', PositionKind::TresCuartos, 30, 'E3717'],
    'tiempo completo' => ['1403 E381700.0100005', PositionKind::TiempoCompleto, 40, 'E3817'],
    'apoyo: jornada de 36 horas' => ['1403 IP0753900.0100006', PositionKind::Apoyo, 36, 'IP07539'],
]);

it('lee varias claves de un renglón, también con separadores y espacios raros del SII', function () {
    $plazas = PlazaKeys::parse("1403 E352504.0100001  1403\\x09E352508.0100002, 14 03 E3525 06.0100003\n1456 E371700. 0100004");

    expect(array_column($plazas, 'category'))->toBe(['E3525', 'E3525', 'E3525', 'E3717'])
        ->and(array_column($plazas, 'hours'))->toBe([4, 8, 6, 30]);
});

it('una categoría sin tipo conocido o una asignatura sin horas quedan sin tipo o sin horas', function () {
    expect(PlazaKeys::parse('1403 E391700.0100001 1403 E352500.0100002'))->toBe([
        ['category' => 'E3917', 'kind' => null, 'hours' => null],
        ['category' => 'E3525', 'kind' => PositionKind::Asignatura, 'hours' => null],
    ]);
});

it('un campo vacío o ilegible no trae plazas', function (string $field) {
    expect(PlazaKeys::parse($field))->toBe([]);
})->with(['vacío' => [''], 'basura' => [', ,'], 'solo números' => ['99.0100001']]);
