<?php

use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Rules\PositionCategories;

it('PL-01 y CV17: la categoría legible guarda la clave del catálogo oficial según la jornada', function (PositionKind $kind, string $name, string $key) {
    $option = collect(PositionCategories::options($kind))->firstWhere('name', $name);

    expect($option['id'])->toBe($key)->and(PositionCategories::label($kind, $key))->toBe($name);
})->with([
    [PositionKind::TiempoCompleto, 'Titular B', 'E3815'],
    [PositionKind::TresCuartos, 'Asociado A', 'E3707'],
    [PositionKind::MedioTiempo, 'Técnico docente titular C', 'E3675'],
    [PositionKind::TiempoCompleto, 'Investigador titular A', 'E3859'],
    [PositionKind::Asignatura, 'Asignatura C', 'E3525'],
    [PositionKind::Asignatura, 'Técnico docente de asignatura A', 'E3505'],
]);

it('PL-01 y CV17: honorarios y apoyo no ofrecen categoría y una clave de otra jornada no vale', function () {
    expect(PositionCategories::options(PositionKind::Honorarios))->toBe([])
        ->and(PositionCategories::options(PositionKind::Apoyo))->toBe([])
        ->and(PositionCategories::allows(PositionKind::TiempoCompleto, 'E3715'))->toBeFalse()
        ->and(PositionCategories::options(PositionKind::Asignatura, 'XX123'))->toContain(['id' => 'XX123', 'name' => 'Clave actual XX123']);
});
