<?php

use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;

it('exige a cada rol las columnas de alcance de PROJECT.md (Actores y roles)', function (Role $role, array $columns) {
    expect($role->scopeColumns())->toBe($columns);
})->with([
    'admin no necesita alcance' => [Role::Admin, []],
    'subdirección: su unidad (Subdirección Académica), sin campus' => [Role::Subdireccion, ['org_unit_id']],
    'R-V2-4: directivo: su unidad (la Dirección o una subdirección), sin campus ni modalidad' => [Role::Directivo, ['org_unit_id']],
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

it('solo el directivo, el jefe de departamento y la Subdirección son uno por unidad en cada fecha (AZ-S7, AZ-03)', function () {
    expect(collect(Role::cases())->filter(fn (Role $role): bool => $role->isExclusive())->values()->all())
        ->toBe([Role::Subdireccion, Role::Directivo, Role::JefeDepartamento]);
});

it('R-V2-4: solo el directivo lee en todo el Tec', function () {
    expect(collect(Role::cases())->filter(fn (Role $role): bool => $role->readsWholeTec())->values()->all())
        ->toBe([Role::Directivo]);
});

it('CF-04: el directivo acota todas las dimensiones (es general en todas para leer); la Subdirección y el jefe, campus y modalidad además de lo que llevan', function () {
    expect(Role::Directivo->boundedDimensions())->toBe(Dimension::cases())
        ->and(Role::Subdireccion->boundedDimensions())->toBe([Dimension::Campus, Dimension::Unit, Dimension::Modality])
        ->and(Role::Docente->boundedDimensions())->toBe([]);
});

it('nombra cada rol como lo nombran PROJECT.md y el glosario, para la credencial y la página de inicio', function (Role $role, string $label) {
    expect($role->label())->toBe($label);
})->with([
    [Role::Admin, 'Administración'],
    [Role::Subdireccion, 'Subdirección Académica'],
    [Role::Directivo, 'Directivo'],
    [Role::JefeDepartamento, 'Jefe de departamento académico'],
    [Role::JefeProyectoDocente, 'Jefe de proyecto docente'],
    [Role::Coordinador, 'Coordinador de carrera'],
    [Role::Docente, 'Docente'],
]);

it('unitKinds: el directivo pide la Dirección o una subdirección; la Subdirección, una subdirección; el jefe, un departamento (académico o no), una división o una oficina; el jefe de proyecto docente, un departamento académico; los demás, ninguna', function (Role $role, array $kinds) {
    expect($role->unitKinds())->toBe($kinds);
})->with([
    [Role::Subdireccion, [OrgUnitKind::Subdireccion]],
    [Role::Directivo, [OrgUnitKind::Direccion, OrgUnitKind::Subdireccion]],
    [Role::JefeDepartamento, [OrgUnitKind::DepartamentoAcademico, OrgUnitKind::Departamento, OrgUnitKind::Division, OrgUnitKind::Oficina]],
    [Role::JefeProyectoDocente, [OrgUnitKind::DepartamentoAcademico]],
    [Role::Coordinador, []],
    [Role::Docente, []],
    [Role::Admin, []],
]);

it('HEADSHIPS son el jefe de departamento y el jefe de proyecto docente', function () {
    expect(Role::HEADSHIPS)->toBe([Role::JefeDepartamento, Role::JefeProyectoDocente]);
});

it('cada puesto describe lo que hace en Nexus para Inicio', function () {
    expect(Role::Docente->description())->toBe('Consultas tu propia carga: los grupos que impartes y sus horarios.')
        ->and(Role::Directivo->description())->toBe('Consultas en modo lectura todo lo académico del Tec.');

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

it('R-V2-4: el directivo se nombra por su unidad; con varias o sin unidad, «Directivo»', function () {
    $direction = ['kind' => OrgUnitKind::Direccion, 'code' => 'DIRECCION', 'name' => 'Dirección'];
    $planning = ['kind' => OrgUnitKind::Subdireccion, 'code' => 'SUB-PLANEACION', 'name' => 'Subdirección de Planeación y Vinculación'];

    expect(Role::Directivo->titleOn([$planning]))->toBe('Subdirección de Planeación y Vinculación')
        ->and(Role::Directivo->titleOn([$direction]))->toBe('Dirección')
        ->and(Role::Directivo->titleOn([$direction, $planning]))->toBe('Directivo')
        ->and(Role::Directivo->titleOn([]))->toBe('Directivo')
        ->and(Role::Directivo->descriptionOn([$planning]))->toBe('Consultas en modo lectura todo lo académico del Tec.')
        ->and(Role::Directivo->listLabel())->toBe('Directivo')
        ->and(Role::Directivo->unitLabel())->toBe('Unidad');
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
    [Role::Directivo, 'Este puesto pide la Dirección o una subdirección que no sea la Académica. Tiene un solo titular vigente.'],
    [Role::JefeDepartamento, 'Este puesto pide un departamento (académico o no), una división o una oficina. Tiene un solo jefe vigente; si la unidad no es académica, solo lleva su título.'],
    [Role::JefeProyectoDocente, 'Este puesto pide modalidad y departamento académico. El campus es opcional: sin campus, trabaja todos los de su modalidad.'],
    [Role::Coordinador, 'Pide una modalidad y una o varias carreras; el campus es opcional: sin campus, trabaja todos los de su modalidad. Se crea un nombramiento por carrera.'],
    [Role::Docente, null],
]);

it('R-V2-4: los títulos de unidad no académica', function () {
    $hr = ['kind' => OrgUnitKind::Departamento, 'code' => 'RECURSOS-HUMANOS', 'name' => 'Departamento de Recursos Humanos'];
    $division = ['kind' => OrgUnitKind::Division, 'code' => 'DIV-PROFESIONAL', 'name' => 'División de Estudios Profesionales'];
    $office = ['kind' => OrgUnitKind::Oficina, 'code' => 'COORDINACION-CAMPUS-II', 'name' => 'Coordinación General Campus II'];

    expect(Role::JefeDepartamento->titleOn([$hr]))->toBe('Jefe del Departamento de Recursos Humanos')
        ->and(Role::JefeDepartamento->titleOn([$division]))->toBe('Jefe de la División de Estudios Profesionales')
        ->and(Role::JefeDepartamento->titleOn([[...$division, 'name' => 'Estudios de Posgrado']]))->toBe('Jefe de la División de Estudios de Posgrado')
        ->and(Role::JefeDepartamento->titleOn([$office]))->toBe('Titular de la Coordinación General Campus II')
        ->and(Role::JefeDepartamento->titleOn([$hr, $office]))->toBe('Jefe de departamento')
        ->and(Role::JefeDepartamento->descriptionOn([$hr]))->toBe('Tu área todavía no tiene funciones en Nexus.')
        ->and(Role::JefeDepartamento->descriptionOn([$division]))->toBe('Tu área todavía no tiene funciones en Nexus.')
        ->and(Role::JefeDepartamento->unitLabel())->toBe('Unidad');
});
