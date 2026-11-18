<?php

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\PermissionSet;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\PermissionCatalogQuery;
use App\Modules\Core\Queries\PermissionSetsQuery;
use App\Modules\Core\Queries\PositionsQuery;
use App\Modules\Students\Enums\StudentsPermission;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/*
| Las Queries de «Puestos y permisos» (CF-01, CF-02, CF-S1, CF-S2): los puestos con sus roles, sus permisos sueltos y
| lo que pueden hoy con su origen; los roles con sus puestos y sus permisos por módulo; y el catálogo de permisos con
| quién los tiene y cuántas personas.
*/

/**
 * @return array<string, mixed>
 */
function positionRowOf(Role $role): array
{
    return collect(app(PositionsQuery::class)->all())->firstWhere('role', $role);
}

it('CF-01: cada puesto sale con sus roles, sus permisos sueltos y el origen de lo que puede hoy', function () {
    PermissionSet::factory()->withPermissions([CorePermission::SettingView, AcademicLoadPermission::ProposalView])->assignedTo(Role::Coordinador)->create(['name' => 'Parámetros']);
    PermissionSet::factory()->inactive()->withPermissions([CorePermission::LoginShortcutView])->assignedTo(Role::Coordinador)->create(['name' => 'Atajos']);
    PermissionSet::factory()->withPermissions([CorePermission::LoginShortcutView])->assignedTo(Role::Coordinador)->create(['name' => 'Borrado'])->delete();
    DB::table('position_permissions')->insert(['role' => Role::Coordinador->value, 'permission' => CorePermission::SettingView->value]);

    $row = positionRowOf(Role::Coordinador);
    $effective = collect($row['effective'])->keyBy('key');
    $expected = [...app(PermissionRegistry::class)->matrix()['coordinador'], 'core.setting.view'];
    sort($expected);

    expect(array_map(fn (array $position): Role => $position['role'], app(PositionsQuery::class)->all()))->toBe(Role::cases())
        ->and($row['label'])->toBe('Coordinador de carrera')
        ->and($row['locked'])->toBeNull()
        ->and(array_column($row['permission_sets'], 'name'))->toBe(['Atajos', 'Coordinador de carrera (de fábrica)', 'Parámetros'])
        ->and(array_column($row['permission_sets'], 'is_active'))->toBe([false, true, true])
        ->and($row['permissions'])->toBe(['core.setting.view'])
        ->and($effective['core.setting.view']['origins'])->toBe(['Rol «Parámetros»', 'Permiso suelto'])
        ->and($effective['academic_load.proposal.view']['origins'])->toBe(['Rol «Coordinador de carrera (de fábrica)»', 'Rol «Parámetros»'])
        ->and($effective['academic_load.proposal.view']['module'])->toBe('academic_load')
        ->and($effective)->not->toHaveKey('core.login_shortcut.view')
        ->and($effective->keys()->sort()->values()->all())->toBe($expected);
});

it('CF-S1: el admin sale de solo lectura, con todo lo declarado menos sus excepciones', function () {
    $row = positionRowOf(Role::Admin);
    $registry = app(PermissionRegistry::class);

    expect($row['locked'])->toBe('El puesto Administración no se edita: tiene todo lo declarado menos sus excepciones.')
        ->and($row['permission_sets'])->toBe([])
        ->and($row['admin_denies'])->toBe($registry->adminDenials())
        ->and(count($row['effective']))->toBe(count($registry->permissions()) - count($registry->adminDenials()))
        ->and(array_unique(array_merge(...array_column($row['effective'], 'origins'))))->toBe([PositionsQuery::ADMIN_ORIGIN])
        ->and(positionRowOf(Role::Coordinador)['admin_denies'])->toBe([]);
});

it('cuenta las personas que ocupan hoy cada puesto, activas y con un nombramiento vigente', function () {
    $campus = Campus::factory()->create();
    User::factory()->count(2)->withRole(Role::Coordinador, ['campus_id' => $campus->id, 'career_id' => careerKey(20)])->create();
    User::factory()->withRole(Role::Coordinador, ['campus_id' => $campus->id, 'career_id' => careerKey(21)])->create(['is_active' => false]);
    User::factory()->admin()->withRole(Role::Docente)->create();
    User::factory()->withRole(Role::Docente)->create()->roleAssignments()->update(['valid_to' => today()->subDay()->toDateString()]);

    expect(positionRowOf(Role::Coordinador)['holders'])->toBe(2)
        ->and(positionRowOf(Role::Docente)['holders'])->toBe(1)
        ->and(positionRowOf(Role::Admin)['holders'])->toBe(1)
        ->and(positionRowOf(Role::Alumno)['holders'])->toBe(0);
});

it('CF-02 y CF-S2: los roles salen sin los borrados, primero los de fábrica, con sus puestos y sus permisos por módulo', function () {
    PermissionSet::factory()->withPermissions([StudentsPermission::StudentView, CorePermission::SettingView, CorePermission::LoginNoticeView])->assignedTo(Role::Coordinador, Role::Subdireccion)->create(['name' => 'Apoyo']);
    PermissionSet::factory()->withPermissions([CorePermission::SettingView])->create(['name' => 'Borrado'])->delete();

    $sets = app(PermissionSetsQuery::class)->all();
    $own = collect($sets)->firstWhere('name', 'Apoyo');
    $docente = collect($sets)->firstWhere('factory_role', Role::Docente);

    expect(array_column($sets, 'name'))->toBe([
        'Subdirección Académica (de fábrica)', 'Jefe de departamento (de fábrica)', 'Jefe de proyecto docente (de fábrica)',
        'Coordinador de carrera (de fábrica)', 'Docente (de fábrica)', 'Alumno (de fábrica)', 'Apoyo',
    ])
        ->and($own['positions'])->toBe([Role::Subdireccion, Role::Coordinador])
        ->and($own['factory_role'])->toBeNull()
        ->and($own['permission_count'])->toBe(3)
        ->and($own['permissions'])->toBe([
            'core' => [['key' => 'core.login_notice.view', 'label' => 'Ver los avisos de la pantalla de acceso'], ['key' => 'core.setting.view', 'label' => 'Ver los parámetros']],
            'students' => [['key' => 'students.student.view', 'label' => StudentsPermission::StudentView->label()]],
        ])
        ->and($docente['follows_factory'])->toBeTrue()
        ->and($docente['positions'])->toBe([Role::Docente])
        ->and($docente['permission_count'])->toBe(count(app(PermissionRegistry::class)->matrix()['docente']))
        ->and($docente['new_factory'])->toBe([])
        ->and(app(PermissionSetsQuery::class)->findOrFail($own['id'])->name)->toBe('Apoyo')
        ->and(fn () => app(PermissionSetsQuery::class)->findOrFail('no-es-un-uuid'))->toThrow(ModelNotFoundException::class);
});

it('CF-03 y CF-S1: el catálogo dice de cada permiso su módulo, sus dimensiones, si es candado y quién lo tiene hoy', function () {
    $campus = Campus::factory()->create();
    User::factory()->count(2)->withRole(Role::Coordinador, ['campus_id' => $campus->id, 'career_id' => careerKey(20)])->create();
    User::factory()->admin()->withRole(Role::Coordinador, ['campus_id' => $campus->id, 'career_id' => careerKey(21)])->create();
    User::factory()->withRole(Role::Docente)->create();
    DB::table('position_permissions')->insert(['role' => Role::Coordinador->value, 'permission' => CorePermission::SettingUpdate->value]);

    $catalog = collect(app(PermissionCatalogQuery::class)->all())->keyBy('key');
    $setting = $catalog['core.setting.update'];

    expect($catalog->keys()->all())->toBe(array_map(fn ($permission): string => $permission->value, app(PermissionRegistry::class)->permissions()))
        ->and($setting['module'])->toBe('core')
        ->and($setting['admin_only'])->toBeFalse()
        ->and(array_map(fn (array $holder): Role => $holder['role'], $setting['positions']))->toBe([Role::Admin, Role::Coordinador])
        ->and($setting['positions'][1]['origins'])->toBe(['Permiso suelto'])
        ->and($setting['people'])->toBe(3)
        ->and($catalog['core.authz.manage']['admin_only'])->toBeTrue()
        ->and($catalog['core.authz.manage']['people'])->toBe(1)
        ->and($catalog['students.student.view']['dimensions'])->toContain(Dimension::Career, Dimension::Unit)
        ->and($catalog['students.student.view']['applies_to'])->not->toBeNull()
        ->and($catalog['staffing.my_load.view']['personal'])->toBeTrue()
        ->and($catalog['staffing.my_load.view']['admin_denied'])->toBeTrue()
        ->and($catalog['staffing.my_load.view']['people'])->toBe(1);
});

it('CF-03 y CF-04: el catálogo marca para el drawer de agregar lo que valdría en todo el Tec y por qué no se da a un puesto', function () {
    $catalog = collect(app(PermissionCatalogQuery::class)->all())->keyBy('key');

    expect($catalog['core.term.create']['institution_wide'])->toBeTrue()
        ->and($catalog['core.term.create']['institution_wide_for'])->toBe([Role::Subdireccion, Role::JefeDepartamento, Role::JefeProyectoDocente, Role::Coordinador])
        ->and($catalog['core.term.open']['institution_wide'])->toBeTrue()
        ->and($catalog['core.term.open']['institution_wide_for'])->toBe([Role::Subdireccion, Role::JefeDepartamento])
        ->and($catalog['core.modality.view']['institution_wide_for'])->toBe([Role::Subdireccion, Role::JefeDepartamento])
        ->and($catalog['students.student.view']['institution_wide'])->toBeFalse()
        ->and($catalog['students.student.view']['institution_wide_for'])->toBe([])
        ->and($catalog['staffing.my_load.view']['institution_wide'])->toBeFalse()
        ->and($catalog['core.campus.update']['blocked_for'])->toBe(array_fill_keys(
            ['subdireccion', 'jefe_departamento', 'jefe_proyecto_docente', 'coordinador', 'docente', 'alumno'],
            '«Editar datos y estado de un campus» es solo del admin: no se da a otro puesto.',
        ))
        ->and(array_keys($catalog['academic_load.load.close']['blocked_for']))->toBe(['coordinador', 'docente', 'alumno'])
        ->and($catalog['core.term.create']['blocked_for'])->toBe([
            'docente' => '«Dar de alta periodos» no se da a Docente: el puesto no lleva alcance y solo recibe permisos personales.',
            'alumno' => '«Dar de alta periodos» no se da a Alumno: el puesto no lleva alcance y solo recibe permisos personales.',
        ]);
});
