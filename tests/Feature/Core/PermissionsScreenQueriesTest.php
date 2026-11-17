<?php

use App\Modules\Core\Authorization\PermissionGrantRules;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\PermissionSet;
use App\Modules\Core\Queries\PermissionCatalogQuery;
use App\Modules\Core\Queries\PermissionSetsQuery;
use App\Modules\Core\Queries\PositionsQuery;
use App\Modules\Faculty\Enums\FacultyPermission;
use App\Modules\Staffing\Enums\StaffingPermission;
use App\Modules\Students\Enums\StudentsPermission;
use Illuminate\Support\Facades\DB;

/*
| Lo que la pantalla «Puestos y permisos» (1.22-B, maqueta B «por puesto») les pide a las Queries del motor: a quién
| cubre cada puesto, el origen de cada permiso como dato (fábrica, otro rol, suelto o todo), los grupos por módulo, los
| textos del alcance y de la aplicabilidad (CF-S1), los roles de la tabla con su búsqueda y su tipo, y los motivos por
| los que un rol o un permiso no se le da a un puesto (CF-03, CF-04, CF-S2). Los motivos salen de PermissionGrantRules:
| la pantalla no inventa los suyos.
*/

function screenPosition(Role $role): array
{
    return collect(app(PositionsQuery::class)->all())->firstWhere('role', $role);
}

function grantProblemOf(Role $role, string $key): ?string
{
    return app(PermissionGrantRules::class)->grantProblem($role, app(PermissionRegistry::class)->forKey($key));
}

it('CF-01: cada permiso efectivo trae su origen como dato: el rol de fábrica del puesto, otro rol o suelto', function () {
    PermissionSet::factory()->withPermissions([CorePermission::SettingView])->assignedTo(Role::Coordinador)->create(['name' => 'Parámetros']);
    DB::table('position_permission_sets')->insert(['role' => Role::Coordinador->value, 'permission_set_id' => PermissionSet::query()->where('factory_role', Role::Docente)->sole()->id]);
    DB::table('position_permissions')->insert(['role' => Role::Coordinador->value, 'permission' => CorePermission::SettingView->value]);

    $row = screenPosition(Role::Coordinador);
    $effective = collect($row['effective'])->keyBy('key');
    $factoryKey = app(PermissionRegistry::class)->matrix()['coordinador'][0];
    $docenteKey = app(PermissionRegistry::class)->matrix()['docente'][0];

    expect($effective['core.setting.view']['sources'])->toBe([['kind' => 'role', 'name' => 'Parámetros'], ['kind' => 'loose', 'name' => null]])
        ->and($effective[$factoryKey]['sources'][0])->toBe(['kind' => 'factory', 'name' => 'Coordinador de carrera (de fábrica)'])
        ->and($effective[$docenteKey]['sources'])->toContain(['kind' => 'role', 'name' => 'Docente (de fábrica)'])
        ->and(collect($row['permission_sets'])->firstWhere('name', 'Docente (de fábrica)')['factory_role'])->toBe(Role::Docente)
        ->and(collect($row['permission_sets'])->firstWhere('name', 'Parámetros')['factory_role'])->toBeNull()
        ->and(array_unique(array_column(array_merge(...array_column(screenPosition(Role::Admin)['effective'], 'sources')), 'kind')))->toBe(['admin']);
});

it('dice a quién cubre cada puesto', function () {
    expect(array_column(app(PositionsQuery::class)->all(), 'scope'))->toBe([
        'Sin alcance: vale en todo Nexus',
        'Su unidad; todo el Tec en campus y modalidad',
        'Su departamento; todo el Tec en campus y modalidad',
        'Su departamento y su modalidad; el campus, si lo lleva',
        'Su carrera y su modalidad; el campus, si lo lleva',
        'Sin alcance: solo permisos personales',
        'Sin alcance: solo permisos personales',
    ]);
});

it('CF-S1: el catálogo dice de cada permiso su grupo, cómo se acota y a qué unidades aplica', function () {
    $catalog = collect(app(PermissionCatalogQuery::class)->all())->keyBy('key');

    expect($catalog['core.campus.view']['group'])->toBe('core_institution')
        ->and($catalog['core.campus.view']['group_label'])->toBe('Institución')
        ->and($catalog['core.setting.view']['group_label'])->toBe('Cuentas y configuración')
        ->and($catalog['faculty.teacher.view']['group_label'])->toBe('Docentes')
        ->and($catalog['staffing.teacher.assign']['group_label'])->toBe('Asignación docente')
        ->and($catalog['students.student.view']['scope_label'])->toBe('Se acota por modalidad, campus, departamento y carrera')
        ->and($catalog['core.campus.view']['scope_label'])->toBe('Se acota por campus')
        ->and($catalog['core.setting.view']['scope_label'])->toBe('Sin alcance: vale en todo Nexus')
        ->and($catalog['staffing.my_load.view']['scope_label'])->toBe('Personal: solo sobre lo propio')
        ->and($catalog['students.student.view']['applies_label'])->toBe('Solo en departamentos académicos y la Subdirección Académica')
        ->and($catalog['academic_load.load.export_sii']['applies_label'])->toBe('Solo en Servicios Escolares y la Subdirección Académica')
        ->and($catalog['core.setting.view']['applies_label'])->toBeNull();
});

it('agrupa los permisos por módulo en el orden de la pantalla, con Core partido en dos', function () {
    $groups = PermissionCatalogQuery::grouped(app(PermissionCatalogQuery::class)->all());

    expect(array_column($groups, 'label'))->toBe([
        'Institución', 'Cuentas y configuración', 'Catálogos académicos', 'Edificios y salones', 'Docentes', 'Alumnos',
        'Importaciones', 'Validación de catálogos', 'Proyección de demanda', 'Carga académica', 'Horarios', 'Asignación docente',
    ])
        ->and(array_sum(array_map(fn (array $group): int => count($group['rows']), $groups)))->toBe(count(app(PermissionRegistry::class)->permissions()))
        ->and(PermissionCatalogQuery::grouped([['key' => 'otro.cosa.ver', 'group' => 'otro', 'group_label' => 'otro']]))->toBe([['id' => 'otro', 'label' => 'otro', 'rows' => [['key' => 'otro.cosa.ver', 'group' => 'otro', 'group_label' => 'otro']]]]);
});

it('el catálogo reusa los puestos que ya se leyeron y trae el origen como dato', function () {
    DB::table('position_permissions')->insert(['role' => Role::Coordinador->value, 'permission' => CorePermission::SettingUpdate->value]);
    $positions = app(PositionsQuery::class)->all();
    DB::table('position_permissions')->delete();

    $setting = collect(app(PermissionCatalogQuery::class)->all($positions))->firstWhere('key', 'core.setting.update');

    expect($setting['positions'][1]['role'])->toBe(Role::Coordinador)
        ->and($setting['positions'][1]['sources'])->toBe([['kind' => 'loose', 'name' => null]]);
});

it('CF-03, CF-04 y CF-S1: dice por qué cada puesto no puede recibir un permiso, con los motivos de las reglas', function () {
    $problems = app(PermissionCatalogQuery::class)->problemsFor('faculty.teacher.view');

    expect(array_keys($problems))->toBe(array_map(fn (Role $role): string => $role->value, Role::cases()))
        ->and($problems['admin'])->toBeNull()
        ->and($problems['jefe_departamento'])->toBeNull()
        ->and($problems['coordinador'])->toBe(grantProblemOf(Role::Coordinador, 'faculty.teacher.view'))->not->toBeNull()
        ->and($problems['docente'])->toBe(grantProblemOf(Role::Docente, 'faculty.teacher.view'))->not->toBeNull()
        ->and(app(PermissionCatalogQuery::class)->problemsFor('staffing.my_load.view')['admin'])->toBe('Es una excepción de Administración: sigue en código.')
        ->and(app(PermissionCatalogQuery::class)->problemsFor('core.authz.view')['coordinador'])->toBe(grantProblemOf(Role::Coordinador, 'core.authz.view'))
        ->and(app(PermissionCatalogQuery::class)->grantProblems(Role::Alumno)[StaffingPermission::MyLoadView->value])->toBeNull()
        ->and(app(PermissionCatalogQuery::class)->grantProblems(Role::Alumno)[CorePermission::TermView->value])->toBe(grantProblemOf(Role::Alumno, 'core.term.view'));
});

it('CF-S2: la tabla de roles busca por nombre sin acentos y filtra por tipo', function () {
    PermissionSet::factory()->withPermissions([CorePermission::SettingView])->create(['name' => 'Consulta de catálogos']);
    PermissionSet::factory()->withPermissions([CorePermission::SettingView])->create(['name' => 'Parámetros']);
    $sets = app(PermissionSetsQuery::class);

    expect(array_column($sets->forTable('CATALOGOS', ''), 'name'))->toBe(['Consulta de catálogos'])
        ->and(array_column($sets->forTable('', 'own'), 'name'))->toBe(['Consulta de catálogos', 'Parámetros'])
        ->and(count($sets->forTable('', 'factory')))->toBe(6)
        ->and(count($sets->forTable('', '')))->toBe(8)
        ->and(count($sets->forTable('', 'otro')))->toBe(8);
});

it('CF-03 y CF-04: dice por qué un rol no se le da a un puesto, con todos los motivos', function () {
    $own = PermissionSet::factory()->withPermissions([CorePermission::SettingView, FacultyPermission::TeacherView, StudentsPermission::StudentView])->create(['name' => 'Mezcla']);
    $problems = app(PermissionSetsQuery::class)->assignProblems(Role::Coordinador);
    $factory = PermissionSet::query()->where('factory_role', Role::Coordinador)->sole();
    $subdireccion = PermissionSet::query()->where('factory_role', Role::Subdireccion)->sole();

    expect($problems[$factory->id])->toBe([])
        ->and($problems[$own->id])->toBe(array_values(array_filter([grantProblemOf(Role::Coordinador, 'faculty.teacher.view'), grantProblemOf(Role::Coordinador, 'students.student.view')])))
        ->and($problems[$subdireccion->id])->not->toBe([])
        ->and(app(PermissionSetsQuery::class)->assignProblems(Role::Admin)[$factory->id])->toBe(['El puesto Administración no se edita: tiene todo lo declarado menos sus excepciones.']);
});

it('CF-03 y CF-S2: en el editor, dice qué no se puede agregar al rol: los candados y lo que algún puesto que lo tiene no acota', function () {
    $set = PermissionSet::factory()->withPermissions([CorePermission::SettingView, FacultyPermission::TeacherView])->assignedTo(Role::JefeDepartamento, Role::Docente)->create(['name' => 'Compartido']);
    $problems = app(PermissionSetsQuery::class)->addProblems($set->id);
    $new = app(PermissionSetsQuery::class)->addProblems(null);

    expect($problems)->not->toHaveKey('core.setting.view')
        ->and($problems)->not->toHaveKey('faculty.teacher.view')
        ->and($problems['core.user.create'])->toBe(app(PermissionGrantRules::class)->lockProblem(CorePermission::UserCreate))
        ->and($problems['core.term.view'])->toBe(grantProblemOf(Role::Docente, 'core.term.view'))
        ->and($problems)->not->toHaveKey('staffing.my_load.view')
        ->and($new['core.user.create'])->toBe(app(PermissionGrantRules::class)->lockProblem(CorePermission::UserCreate))
        ->and($new)->not->toHaveKey('core.term.view');
});
