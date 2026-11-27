<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Support\HomePanels;

/*
| HomePanels (AC-15, 1.85): cada puesto registra su componente y, si quiere, el subtítulo de su encabezado.
*/

it('da el subtítulo que registró el módulo para el puesto', function () {
    $panels = new HomePanels;
    $panels->register('docente', 'staffing::components.teacher-summary', 'Tus clases de hoy.');

    expect($panels->subtitleFor(Role::Docente))->toBe('Tus clases de hoy.')
        ->and($panels->for(Role::Docente))->toBe('staffing::components.teacher-summary');
});

it('devuelve null sin subtítulo registrado, o sin panel para el puesto', function () {
    $panels = new HomePanels;
    $panels->register('docente', 'staffing::components.teacher-summary');

    expect($panels->subtitleFor(Role::Docente))->toBeNull()
        ->and($panels->subtitleFor(Role::Alumno))->toBeNull();
});
