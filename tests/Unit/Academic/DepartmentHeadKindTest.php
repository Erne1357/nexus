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
