<?php

use App\Modules\Core\Enums\Role;

it('exige a cada rol las columnas de alcance de PROJECT.md (Actores y roles)', function (Role $role, array $columns) {
    expect($role->scopeColumns())->toBe($columns);
})->with([
    'admin no necesita alcance' => [Role::Admin, []],
    'subdirección: un campus' => [Role::Subdireccion, ['campus_id']],
    'jefe de departamento: un departamento en un campus' => [Role::JefeDepartamento, ['campus_id', 'department_id']],
    'jefe de proyecto docente: un departamento en un campus' => [Role::JefeProyectoDocente, ['campus_id', 'department_id']],
    'coordinador: una carrera en un campus' => [Role::Coordinador, ['campus_id', 'career_id']],
    'docente: un campus donde tiene carga' => [Role::Docente, ['campus_id']],
]);
