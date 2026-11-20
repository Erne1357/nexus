<?php

use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\NavigationGroup;

/*
| Grupos del menú dentro de un área (MN-01): Académico lleva la validación, Carga académica va por etapa del semestre, en
| el orden de Stage (tarea 1.24.6), y Administración por propósito. El orden de los casos es el del menú dentro de su área.
*/

it('MN-01: declara los grupos en el orden del menú', function () {
    expect(array_map(fn (NavigationGroup $group): string => $group->value, NavigationGroup::cases()))->toBe([
        'validacion',
        'propuesta',
        'horarios',
        'asignacion',
        'cierre_publicacion',
        'institucion',
        'catalogos',
        'datos',
        'configuracion',
    ]);
});

it('MN-01: cada grupo vive en un área y se nombra en español', function (NavigationGroup $group, NavigationArea $area, string $label) {
    expect($group->area())->toBe($area)
        ->and($group->label())->toBe($label);
})->with([
    'validación' => [NavigationGroup::Validacion, NavigationArea::Academico, 'Validación'],
    'propuesta' => [NavigationGroup::Propuesta, NavigationArea::CargaAcademica, 'Propuesta'],
    'horarios' => [NavigationGroup::Horarios, NavigationArea::CargaAcademica, 'Horarios'],
    'asignación' => [NavigationGroup::Asignacion, NavigationArea::CargaAcademica, 'Asignación'],
    'cierre y publicación' => [NavigationGroup::CierrePublicacion, NavigationArea::CargaAcademica, 'Cierre y publicación'],
    'institución' => [NavigationGroup::Institucion, NavigationArea::Administracion, 'Institución'],
    'catálogos' => [NavigationGroup::Catalogos, NavigationArea::Administracion, 'Catálogos'],
    'datos' => [NavigationGroup::Datos, NavigationArea::Administracion, 'Datos'],
    'configuración' => [NavigationGroup::Configuracion, NavigationArea::Administracion, 'Configuración'],
]);
