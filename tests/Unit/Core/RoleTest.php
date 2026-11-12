<?php

use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;

it('exige a cada rol las columnas de alcance de PROJECT.md (Actores y roles)', function (Role $role, array $columns) {
    expect($role->scopeColumns())->toBe($columns);
})->with([
    'admin no necesita alcance' => [Role::Admin, []],
    'subdirección: su unidad (Subdirección Académica), sin campus' => [Role::Subdireccion, ['org_unit_id']],
    'jefe de departamento: la unidad de un departamento en todo el Tec' => [Role::JefeDepartamento, ['org_unit_id']],
    'AZ-S17: jefe de proyecto docente: la unidad de un departamento en una modalidad' => [Role::JefeProyectoDocente, ['modality_id', 'org_unit_id']],
    'AZ-S17: coordinador: una carrera en una modalidad' => [Role::Coordinador, ['modality_id', 'career_id']],
    'docente: todo el Tec, sin campus' => [Role::Docente, []],
]);

it('AZ-S17: el coordinador y el jefe de proyecto docente admiten el campus sin pedirlo; los demás puestos, nada', function () {
    expect(collect(Role::cases())->mapWithKeys(fn (Role $role): array => [$role->value => $role->optionalScopeColumns()])->filter()->all())->toBe([
        'jefe_proyecto_docente' => ['campus_id'],
        'coordinador' => ['campus_id'],
    ]);
});

it('solo el jefe de departamento y la Subdirección son uno por unidad en cada fecha (AZ-S7)', function () {
    expect(collect(Role::cases())->filter(fn (Role $role): bool => $role->isExclusive())->values()->all())
        ->toBe([Role::Subdireccion, Role::JefeDepartamento]);
});

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

it('unitKind: la Subdirección pide una subdirección; el jefe y el jefe de proyecto docente, un departamento académico; los demás, ninguna', function (Role $role, ?OrgUnitKind $kind) {
    expect($role->unitKind())->toBe($kind);
})->with([
    [Role::Subdireccion, OrgUnitKind::Subdireccion],
    [Role::JefeDepartamento, OrgUnitKind::DepartamentoAcademico],
    [Role::JefeProyectoDocente, OrgUnitKind::DepartamentoAcademico],
    [Role::Coordinador, null],
    [Role::Docente, null],
    [Role::Admin, null],
]);

it('HEADSHIPS son el jefe de departamento y el jefe de proyecto docente', function () {
    expect(Role::HEADSHIPS)->toBe([Role::JefeDepartamento, Role::JefeProyectoDocente]);
});

it('cada puesto describe lo que hace en Nexus para Inicio', function () {
    expect(Role::Docente->description())->toBe('Consultas tu propia carga: los grupos que impartes y sus horarios.');

    foreach (Role::cases() as $role) {
        expect($role->description())->not->toBe('');
    }
});

it('AZ-02: el jefe de una unidad que no es departamento académico se nombra por ella; los demás conservan su nombre', function () {
    $services = ['kind' => OrgUnitKind::Departamento, 'code' => 'SERVICIOS-ESCOLARES', 'name' => 'Departamento de Servicios Escolares'];
    $systems = ['kind' => OrgUnitKind::DepartamentoAcademico, 'code' => 'SC', 'name' => 'Sistemas y Computación'];

    expect(Role::JefeDepartamento->titleOn([$services]))->toBe('Jefe del Departamento de Servicios Escolares')
        ->and(Role::JefeDepartamento->titleOn([[...$services, 'name' => 'Servicios Escolares']]))->toBe('Jefe del Departamento de Servicios Escolares')
        ->and(Role::JefeDepartamento->titleOn([$systems]))->toBe('Jefe de departamento académico')
        ->and(Role::JefeDepartamento->titleOn([$systems, $services]))->toBe('Jefe de departamento')
        ->and(Role::JefeDepartamento->titleOn([]))->toBe('Jefe de departamento académico')
        ->and(Role::JefeProyectoDocente->titleOn([$systems]))->toBe('Jefe de proyecto docente');
});

it('AZ-S16: en una lista para elegir el puesto, sin persona ni unidad, el jefe de departamento es «Jefe de departamento»', function (Role $role, string $label) {
    expect($role->listLabel())->toBe($label);
})->with([
    [Role::Admin, 'Administración'],
    [Role::Subdireccion, 'Subdirección Académica'],
    [Role::JefeDepartamento, 'Jefe de departamento'],
    [Role::JefeProyectoDocente, 'Jefe de proyecto docente'],
    [Role::Coordinador, 'Coordinador de carrera'],
    [Role::Docente, 'Docente'],
]);

it('la pista del formulario «Nombrar» dice qué alcance pide cada puesto', function (Role $role, ?string $hint) {
    expect($role->appointHint())->toBe($hint);
})->with([
    [Role::Admin, 'Este puesto no tiene alcance: vale en todo el sistema.'],
    [Role::Subdireccion, 'Este puesto pide la Subdirección Académica. Tiene un solo titular vigente.'],
    [Role::JefeDepartamento, 'Este puesto pide el departamento académico o Servicios Escolares. Tiene un solo jefe vigente.'],
    [Role::JefeProyectoDocente, 'Este puesto pide modalidad y departamento académico. El campus es opcional: sin campus, trabaja todos los de su modalidad.'],
    [Role::Coordinador, 'Este puesto pide modalidad y carrera. El campus es opcional: sin campus, trabaja todos los de su modalidad.'],
    [Role::Docente, null],
]);
