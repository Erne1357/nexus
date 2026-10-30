<?php

use App\Modules\Academic\Enums\DepartmentHeadKind;
use App\Modules\Core\Enums\Role;

it('cada tipo de jefe tiene su etiqueta y el rol que da (tarea 1.14)', function (DepartmentHeadKind $kind, string $label, Role $role) {
    expect($kind->label())->toBe($label)
        ->and($kind->role())->toBe($role);
})->with([
    [DepartmentHeadKind::Jefe, 'Jefe de departamento', Role::JefeDepartamento],
    [DepartmentHeadKind::JefeProyectoDocente, 'Jefe de proyecto docente', Role::JefeProyectoDocente],
]);

it('nombra el cargo en un departamento: el del jefe sin campus, el del jefe de proyecto docente con el suyo', function (DepartmentHeadKind $kind, ?string $campus, string $position) {
    expect($kind->position('Sistemas y Computación', $campus))->toBe($position);
})->with([
    'jefe' => [DepartmentHeadKind::Jefe, null, 'jefe de departamento de Sistemas y Computación'],
    'jefe de proyecto docente' => [DepartmentHeadKind::JefeProyectoDocente, 'Campus II', 'jefe de proyecto docente de Sistemas y Computación en Campus II'],
]);
