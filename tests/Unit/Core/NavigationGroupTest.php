<?php

use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\NavigationGroup;

/*
| Grupos del menú dentro de un área (MN-01): Académico por etapa del semestre, en el orden de Stage, y
| Administración por propósito. El orden de los casos es el del menú dentro de su área.
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
    ]);
});

it('MN-01: cada grupo vive en un área y se nombra en español', function (NavigationGroup $group, NavigationArea $area, string $label) {
    expect($group->area())->toBe($area)
        ->and($group->label())->toBe($label);
})->with([
    'validación' => [NavigationGroup::Validacion, NavigationArea::Academico, 'Validación'],
    'propuesta' => [NavigationGroup::Propuesta, NavigationArea::Academico, 'Propuesta'],
    'horarios' => [NavigationGroup::Horarios, NavigationArea::Academico, 'Horarios'],
    'asignación' => [NavigationGroup::Asignacion, NavigationArea::Academico, 'Asignación'],
    'cierre y publicación' => [NavigationGroup::CierrePublicacion, NavigationArea::Academico, 'Cierre y publicación'],
    'institución' => [NavigationGroup::Institucion, NavigationArea::Administracion, 'Institución'],
    'catálogos' => [NavigationGroup::Catalogos, NavigationArea::Administracion, 'Catálogos'],
    'datos' => [NavigationGroup::Datos, NavigationArea::Administracion, 'Datos'],
]);
