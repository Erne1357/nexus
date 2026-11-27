<?php

use App\Modules\Core\Enums\NavigationArea;

/*
| Áreas del menú (ADR-0015, DESIGN.md "Navigation", MN-01): el orden de los casos es el del menú. Desde la
| tarea 1.20, Administración reúne la configuración (lo que era del área Datos incluido) y Académico queda
| con el trabajo del semestre; desde la tarea 1.24.6, todo lo de la carga va en Carga académica, justo después de
| Académico, que se queda con la validación de catálogos. Desde la tarea 1.34, Comunicación y Difusión (CO-01) va después de
| Servicios escolares.
*/

it('MN-01: declara las áreas en el orden del menú, con Carga académica después de Académico, Comunicación y Difusión después de Servicios escolares, Administración al final y sin Datos', function () {
    expect(array_map(fn (NavigationArea $area): string => $area->value, NavigationArea::cases()))->toBe([
        'mi_espacio',
        'academico',
        'carga_academica',
        'servicios_escolares',
        'comunicacion',
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
    'carga académica' => [NavigationArea::CargaAcademica, 'Carga académica', 'o-table-cells'],
    'servicios escolares' => [NavigationArea::ServiciosEscolares, 'Servicios escolares', 'o-user-group'],
    'comunicación y difusión' => [NavigationArea::Comunicacion, 'Comunicación y Difusión', 'o-megaphone'],
    'recursos humanos' => [NavigationArea::RecursosHumanos, 'Recursos humanos', 'o-identification'],
    'recursos financieros' => [NavigationArea::RecursosFinancieros, 'Recursos financieros', 'o-banknotes'],
    'administración' => [NavigationArea::Administracion, 'Administración', 'o-cog-6-tooth'],
]);
