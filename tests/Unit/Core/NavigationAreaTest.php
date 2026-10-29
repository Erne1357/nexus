<?php

use App\Modules\Core\Enums\NavigationArea;

/*
| Áreas del menú (ADR-0015, DESIGN.md "Navigation"): el orden de los casos es el del menú.
*/

it('declara las áreas en el orden del menú', function () {
    expect(array_map(fn (NavigationArea $area): string => $area->value, NavigationArea::cases()))->toBe([
        'academico',
        'servicios_escolares',
        'recursos_humanos',
        'recursos_financieros',
        'datos',
    ]);
});

it('nombra cada área en español y le da su icono de Heroicon', function (NavigationArea $area, string $label, string $icon) {
    expect($area->label())->toBe($label)
        ->and($area->icon())->toBe($icon);
})->with([
    'académico' => [NavigationArea::Academico, 'Académico', 'o-academic-cap'],
    'servicios escolares' => [NavigationArea::ServiciosEscolares, 'Servicios escolares', 'o-user-group'],
    'recursos humanos' => [NavigationArea::RecursosHumanos, 'Recursos humanos', 'o-identification'],
    'recursos financieros' => [NavigationArea::RecursosFinancieros, 'Recursos financieros', 'o-banknotes'],
    'datos' => [NavigationArea::Datos, 'Datos', 'o-circle-stack'],
]);
