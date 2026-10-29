<?php

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Enums\SubjectKind;

it('cada tipo de materia tiene su etiqueta', function (SubjectKind $kind, string $label) {
    expect($kind->label())->toBe($label);
})->with([
    [SubjectKind::Basica, 'Básica'],
    [SubjectKind::Especialidad, 'Especialidad'],
    [SubjectKind::Complementaria, 'Complementaria'],
    [SubjectKind::Residencia, 'Residencia profesional'],
    [SubjectKind::ServicioSocial, 'Servicio social'],
    [SubjectKind::LenguaExtranjera, 'Lengua extranjera'],
]);

it('cada tipo de seriación tiene su etiqueta', function (PrerequisiteKind $kind, string $label) {
    expect($kind->label())->toBe($label);
})->with([
    [PrerequisiteKind::Seriacion, 'Seriación'],
    [PrerequisiteKind::Correquisito, 'Correquisito'],
]);
