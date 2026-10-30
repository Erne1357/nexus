<?php

use App\Modules\Core\Enums\Role;

it('exige a cada rol las columnas de alcance de PROJECT.md (Actores y roles)', function (Role $role, array $columns) {
    expect($role->scopeColumns())->toBe($columns);
})->with([
    'admin no necesita alcance' => [Role::Admin, []],
    'subdirección: todo el Tec, sin campus' => [Role::Subdireccion, []],
    'jefe de departamento: un departamento en todo el Tec' => [Role::JefeDepartamento, ['department_id']],
    'jefe de proyecto docente: un departamento en un campus' => [Role::JefeProyectoDocente, ['campus_id', 'department_id']],
    'coordinador: una carrera en un campus' => [Role::Coordinador, ['campus_id', 'career_id']],
    'docente: todo el Tec, sin campus' => [Role::Docente, []],
]);

it('nombra cada rol como lo nombran PROJECT.md y el glosario, para la credencial y la página de inicio', function (Role $role, string $label) {
    expect($role->label())->toBe($label);
})->with([
    [Role::Admin, 'Administración'],
    [Role::Subdireccion, 'Subdirección Académica'],
    [Role::JefeDepartamento, 'Jefe de departamento académico'],
    [Role::JefeProyectoDocente, 'Jefe de proyecto docente'],
    [Role::Coordinador, 'Coordinador de carrera'],
    [Role::Docente, 'Docente'],
]);
