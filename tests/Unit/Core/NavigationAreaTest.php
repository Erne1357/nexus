<?php

use App\Modules\Core\Enums\NavigationArea;

/*
| Áreas del menú (ADR-0015, DESIGN.md "Navigation", MN-01): el orden de los casos es el del menú. Desde la
| tarea 1.20, Administración reúne la configuración (lo que era del área Datos incluido) y Académico queda
| con el trabajo del semestre.
*/

it('MN-01: declara las áreas en el orden del menú, con Administración al final y sin Datos', function () {
    expect(array_map(fn (NavigationArea $area): string => $area->value, NavigationArea::cases()))->toBe([
        'mi_espacio',
        'academico',
        'servicios_escolares',
        'recursos_humanos',
        'recursos_financieros',
        'administracion',
    ]);
});

it('nombra cada área en español y le da su icono de Heroicon', function (NavigationArea $area, string $label, string $icon) {
    expect($area->label())->toBe($label)
        ->and($area->icon())->toBe($icon);
})->with([
    'mi espacio' => [NavigationArea::MiEspacio, 'Mi espacio', 'o-user-circle'],
    'académico' => [NavigationArea::Academico, 'Académico', 'o-academic-cap'],
    'servicios escolares' => [NavigationArea::ServiciosEscolares, 'Servicios escolares', 'o-user-group'],
    'recursos humanos' => [NavigationArea::RecursosHumanos, 'Recursos humanos', 'o-identification'],
    'recursos financieros' => [NavigationArea::RecursosFinancieros, 'Recursos financieros', 'o-banknotes'],
    'administración' => [NavigationArea::Administracion, 'Administración', 'o-cog-6-tooth'],
]);
