<?php

use App\Modules\Core\Actions\ApplyFactoryUpdates;
use App\Modules\Core\Actions\AssignPermissionSet;
use App\Modules\Core\Actions\GrantPositionPermission;
use App\Modules\Core\Actions\RestoreFactoryPermissionSet;
use App\Modules\Core\Actions\RestoreFactoryPosition;
use App\Modules\Core\Actions\RevokePositionPermission;
use App\Modules\Core\Actions\UnassignPermissionSet;
use App\Modules\Core\Actions\UpdatePermissionSet;
use App\Modules\Core\Authorization\GrantTable;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\PermissionSet;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\PermissionSetsQuery;
use App\Modules\Faculty\Enums\FacultyPermission;
use App\Modules\Staffing\Enums\StaffingPermission;
use App\Modules\Students\Enums\StudentsPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Fixtures\Authorization\FakePermission;

/*
| Las Actions de los puestos (CF-01 a CF-04 y CF-S1): dar y quitar roles y permisos sueltos, restablecer un rol de
| fábrica o un puesto, y aplicar los permisos nuevos de fábrica.
*/

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

/**
 * Los mensajes de la ValidationException que lanza `$action`, por campo.
 *
 * @return array<string, list<string>>
 */
function positionActionErrors(Closure $action): array
{
    try {
        $action();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('No lanzó ValidationException.');
}

function factorySetOfPosition(Role $role): PermissionSet
{
    return PermissionSet::query()->where('factory_role', $role->value)->sole();
}

/**
 * @return list<string>
 */
function positionSetKeys(PermissionSet $set): array
{
    return DB::table('permission_set_permissions')->where('permission_set_id', $set->id)->orderBy('permission')->pluck('permission')->all();
}

/**
 * @return list<string>
 */
function positionSetNames(Role $role): array
{
    return DB::table('position_permission_sets')
        ->join('permission_sets', 'permission_sets.id', '=', 'position_permission_sets.permission_set_id')
        ->where('role', $role->value)->orderBy('name')->pluck('name')->all();
}

it('CF-S1: el admin no se edita', function (Closure $action) {
    expect(positionActionErrors(fn () => $action()))
        ->toBe(['role' => ['El puesto Administración no se edita: tiene todo lo declarado menos sus excepciones.']])
        ->and(DB::table('position_permission_sets')->where('role', 'admin')->exists())->toBeFalse();
})->with([
    'dar un rol' => [fn () => app(AssignPermissionSet::class)->handle(Role::Admin, PermissionSet::factory()->withPermissions([CorePermission::SettingView])->create())],
    'quitar un rol' => [fn () => app(UnassignPermissionSet::class)->handle(Role::Admin, factorySetOfPosition(Role::Docente))],
    'dar un permiso suelto' => [fn () => app(GrantPositionPermission::class)->handle(Role::Admin, CorePermission::SettingView)],
    'quitar un permiso suelto' => [fn () => app(RevokePositionPermission::class)->handle(Role::Admin, CorePermission::SettingView)],
    'restablecer el puesto' => [fn () => app(RestoreFactoryPosition::class)->handle(Role::Admin)],
]);

it('CF-01: da un rol a un puesto y lo deja en la bitácora con lo de antes y lo de después', function () {
    $set = PermissionSet::factory()->withPermissions([CorePermission::SettingView])->create(['name' => 'Parámetros']);

    app(AssignPermissionSet::class)->handle(Role::Coordinador, $set);
    app(AssignPermissionSet::class)->handle(Role::Coordinador, $set);

    expect(positionSetNames(Role::Coordinador))->toBe(['Coordinador de carrera (de fábrica)', 'Parámetros'])
        ->and(app(GrantTable::class)->grants(Role::Coordinador, CorePermission::SettingView))->toBeTrue()
        ->and(Activity::sole()->subject->is($set))->toBeTrue()
        ->and(Activity::sole()->properties['position'])->toBe('coordinador')
        ->and(Activity::sole()->attribute_changes->toArray())->toBe([
            'attributes' => ['permission_sets' => ['Coordinador de carrera (de fábrica)', 'Parámetros'], 'permissions' => []],
            'old' => ['permission_sets' => ['Coordinador de carrera (de fábrica)'], 'permissions' => []],
        ]);
});

it('CF-03: no se da un rol que traiga un candado', function () {
    $set = PermissionSet::factory()->withPermissions([CorePermission::SettingView, CorePermission::UserCreate])->create();

    expect(positionActionErrors(fn () => app(AssignPermissionSet::class)->handle(Role::Subdireccion, $set)))
        ->toBe(['permission_set' => ['«Dar de alta usuarios del personal» es solo del admin: no se da a otro puesto.']])
        ->and(positionSetNames(Role::Subdireccion))->toBe(['Subdirección Académica (de fábrica)']);
});

it('CF-04: no se da un rol con un permiso que el puesto no acota, salvo lo de fábrica del puesto', function () {
    $coordinatorFactory = factorySetOfPosition(Role::Coordinador);

    expect(positionActionErrors(fn () => app(AssignPermissionSet::class)->handle(Role::JefeProyectoDocente, $coordinatorFactory))['permission_set'])
        ->toContain('«Ver alumnos» no se da a Jefe de proyecto docente: el puesto no lleva carrera y el permiso valdría en todas las carreras.')
        ->and(positionActionErrors(fn () => app(AssignPermissionSet::class)->handle(Role::Docente, PermissionSet::factory()->withPermissions([CorePermission::SettingView])->create())))
        ->toBe(['permission_set' => ['«Ver los parámetros» no se da a Docente: el puesto no lleva alcance y solo recibe permisos personales.']]);

    app(UnassignPermissionSet::class)->handle(Role::Coordinador, $coordinatorFactory);
    app(AssignPermissionSet::class)->handle(Role::Coordinador, $coordinatorFactory);

    expect(positionSetNames(Role::Coordinador))->toBe(['Coordinador de carrera (de fábrica)']);
});

it('CF-S2: un rol desactivado se puede dar, pero no aporta', function () {
    $set = PermissionSet::factory()->inactive()->withPermissions([CorePermission::SettingView])->create();

    app(AssignPermissionSet::class)->handle(Role::Coordinador, $set);

    expect(positionSetNames(Role::Coordinador))->toContain($set->name)
        ->and(app(GrantTable::class)->grants(Role::Coordinador, CorePermission::SettingView))->toBeFalse();
});

it('CF-01: quita un rol de un puesto, también el de fábrica', function () {
    app(UnassignPermissionSet::class)->handle(Role::Docente, factorySetOfPosition(Role::Docente));
    app(UnassignPermissionSet::class)->handle(Role::Docente, factorySetOfPosition(Role::Docente));

    expect(positionSetNames(Role::Docente))->toBe([])
        ->and(app(GrantTable::class)->matrix())->not->toHaveKey('docente')
        ->and(Activity::sole()->attribute_changes->toArray())->toBe([
            'attributes' => ['permission_sets' => [], 'permissions' => []],
            'old' => ['permission_sets' => ['Docente (de fábrica)'], 'permissions' => []],
        ]);
});

it('CF-01: da y quita un permiso suelto, con la bitácora del puesto', function () {
    app(GrantPositionPermission::class)->handle(Role::JefeProyectoDocente, CorePermission::LoginNoticeView);
    app(GrantPositionPermission::class)->handle(Role::JefeProyectoDocente, CorePermission::LoginNoticeView);

    expect(app(GrantTable::class)->grants(Role::JefeProyectoDocente, CorePermission::LoginNoticeView))->toBeTrue()
        ->and(Activity::sole()->description)->toBe('Dio el permiso «Ver los avisos de la pantalla de acceso» al puesto Jefe de proyecto docente')
        ->and(Activity::sole()->attribute_changes['attributes']['permissions'])->toBe(['core.login_notice.view']);

    app(RevokePositionPermission::class)->handle(Role::JefeProyectoDocente, CorePermission::LoginNoticeView);
    app(RevokePositionPermission::class)->handle(Role::JefeProyectoDocente, CorePermission::LoginNoticeView);

    expect(app(GrantTable::class)->grants(Role::JefeProyectoDocente, CorePermission::LoginNoticeView))->toBeFalse()
        ->and(Activity::count())->toBe(2)
        ->and(Activity::query()->latest('id')->first()->attribute_changes->toArray())->toBe([
            'attributes' => ['permission_sets' => ['Jefe de proyecto docente (de fábrica)'], 'permissions' => []],
            'old' => ['permission_sets' => ['Jefe de proyecto docente (de fábrica)'], 'permissions' => ['core.login_notice.view']],
        ]);
});

it('CF-03 y CF-04: un permiso suelto no es candado ni amplía el alcance del puesto', function (Role $role, CorePermission|FacultyPermission|StudentsPermission|StaffingPermission $permission, ?string $problem) {
    $action = fn () => app(GrantPositionPermission::class)->handle($role, $permission);

    if ($problem === null) {
        $action();

        expect(app(GrantTable::class)->grants($role, $permission))->toBeTrue();

        return;
    }

    expect(positionActionErrors($action))->toBe(['permission' => [$problem]])
        ->and(DB::table('position_permissions')->exists())->toBeFalse();
})->with([
    'candado' => [Role::JefeDepartamento, CorePermission::OrgUnitView, '«Ver el organigrama» es solo del admin: no se da a otro puesto.'],
    'el docente no recibe gestión' => [Role::Docente, CorePermission::TermView, '«Ver periodos» no se da a Docente: el puesto no lleva alcance y solo recibe permisos personales.'],
    'el coordinador no lleva departamento' => [Role::Coordinador, FacultyPermission::TeacherView, '«Ver docentes» no se da a Coordinador de carrera: el puesto no lleva departamento y el permiso valdría en todos los departamentos.'],
    'el jefe de proyecto docente no lleva carrera' => [Role::JefeProyectoDocente, StudentsPermission::StudentView, '«Ver alumnos» no se da a Jefe de proyecto docente: el puesto no lleva carrera y el permiso valdría en todas las carreras.'],
    'el jefe de proyecto docente acota la unidad' => [Role::JefeProyectoDocente, FacultyPermission::TeacherView, null],
    'el alumno recibe un permiso personal' => [Role::Alumno, StaffingPermission::MyLoadView, null],
]);

it('CF-02: restablecer un puesto lo deja solo con su rol de fábrica y sin permisos sueltos', function () {
    PermissionSet::factory()->withPermissions([CorePermission::SettingView])->assignedTo(Role::Coordinador)->create(['name' => 'Parámetros']);
    app(GrantPositionPermission::class)->handle(Role::Coordinador, CorePermission::LoginNoticeView);
    app(UnassignPermissionSet::class)->handle(Role::Coordinador, factorySetOfPosition(Role::Coordinador));
    $before = Activity::count();

    app(RestoreFactoryPosition::class)->handle(Role::Coordinador);
    app(RestoreFactoryPosition::class)->handle(Role::Coordinador);

    expect(positionSetNames(Role::Coordinador))->toBe(['Coordinador de carrera (de fábrica)'])
        ->and(DB::table('position_permissions')->exists())->toBeFalse()
        ->and(app(GrantTable::class)->matrix()['coordinador'])->toBe(app(PermissionRegistry::class)->matrix()['coordinador'])
        ->and(Activity::count())->toBe($before + 1)
        ->and(Activity::query()->latest('id')->first()->attribute_changes->toArray())->toBe([
            'attributes' => ['permission_sets' => ['Coordinador de carrera (de fábrica)'], 'permissions' => []],
            'old' => ['permission_sets' => ['Parámetros'], 'permissions' => ['core.login_notice.view']],
        ]);
});

it('CF-04: restablecer un puesto revisa su rol de fábrica si el puesto ya no lo tenía', function () {
    $set = factorySetOfPosition(Role::Coordinador);
    app(UnassignPermissionSet::class)->handle(Role::Coordinador, $set);
    app(UpdatePermissionSet::class)->handle($set, ['permissions' => [StudentsPermission::StudentView->value, FacultyPermission::TeacherView->value]]);

    expect(positionActionErrors(fn () => app(RestoreFactoryPosition::class)->handle(Role::Coordinador)))
        ->toBe(['role' => ['«Ver docentes» no se da a Coordinador de carrera: el puesto no lleva departamento y el permiso valdría en todos los departamentos.']])
        ->and(positionSetNames(Role::Coordinador))->toBe([]);

    app(RestoreFactoryPermissionSet::class)->handle($set);
    app(RestoreFactoryPosition::class)->handle(Role::Coordinador);

    expect(positionSetNames(Role::Coordinador))->toBe(['Coordinador de carrera (de fábrica)']);
});

it('CF-02: restablecer un rol de fábrica editado lo regresa a seguir al código', function () {
    $set = factorySetOfPosition(Role::Docente);
    app(UpdatePermissionSet::class)->handle($set, ['permissions' => [StaffingPermission::MyLoadView->value]]);

    app(RestoreFactoryPermissionSet::class)->handle($set);
    app(RestoreFactoryPermissionSet::class)->handle($set);

    expect($set->fresh()->follows_factory)->toBeTrue()
        ->and($set->fresh()->factory_baseline)->toBeNull()
        ->and(positionSetKeys($set))->toBe([])
        ->and(app(GrantTable::class)->matrix()['docente'])->toBe(app(PermissionRegistry::class)->matrix()['docente'])
        ->and(Activity::count())->toBe(2)
        ->and(positionActionErrors(fn () => app(RestoreFactoryPermissionSet::class)->handle(PermissionSet::factory()->withPermissions([CorePermission::SettingView])->create(['name' => 'Propio']))))
        ->toBe(['permission_set' => ['«Propio» no es un rol de fábrica: no se restablece.']]);
});

it('CF-02: «Aplicar» agrega lo que el código concedió después de editar el rol, no lo que el admin quitó', function () {
    $registry = fn (array $coordinator): PermissionRegistry => PermissionRegistry::fromDeclarations([FakePermission::class], [Role::Coordinador->value => $coordinator], []);
    app()->instance(PermissionRegistry::class, $registry([FakePermission::ViewCampus, FakePermission::ViewOwn]));
    app()->forgetScopedInstances();
    $set = factorySetOfPosition(Role::Coordinador);

    app(UpdatePermissionSet::class)->handle($set, ['permissions' => [FakePermission::ViewCampus->value]]);

    expect(collect(app(PermissionSetsQuery::class)->all())->firstWhere('id', $set->id)['new_factory'])->toBe([]);

    app()->instance(PermissionRegistry::class, $registry([FakePermission::ViewCampus, FakePermission::ViewOwn, FakePermission::ViewCareer]));
    app()->forgetScopedInstances();

    expect(collect(app(PermissionSetsQuery::class)->all())->firstWhere('id', $set->id)['new_factory'])->toBe([FakePermission::ViewCareer->value]);

    app(ApplyFactoryUpdates::class)->handle($set);
    app(ApplyFactoryUpdates::class)->handle($set);

    expect(positionSetKeys($set))->toBe([FakePermission::ViewCampus->value, FakePermission::ViewCareer->value])
        ->and($set->fresh()->factory_baseline)->toBe(['fake.thing.view_campus', 'fake.thing.view_career', 'fake.thing.view_own'])
        ->and(collect(app(PermissionSetsQuery::class)->all())->firstWhere('id', $set->id)['new_factory'])->toBe([])
        ->and(app(GrantTable::class)->matrix()['coordinador'])->toBe(['fake.thing.view_campus', 'fake.thing.view_career'])
        ->and(Activity::query()->latest('id')->first()->attribute_changes->toArray())->toBe([
            'attributes' => ['permissions' => ['fake.thing.view_campus', 'fake.thing.view_career']],
            'old' => ['permissions' => ['fake.thing.view_campus']],
        ])
        ->and(Activity::count())->toBe(2);
});

it('CF-04: «Aplicar» y «Restablecer» no le traen a otro puesto lo que no acota', function () {
    $registry = fn (array $coordinator): PermissionRegistry => PermissionRegistry::fromDeclarations([FakePermission::class], [Role::Coordinador->value => $coordinator], []);
    app()->instance(PermissionRegistry::class, $registry([FakePermission::ViewCampus, FakePermission::ViewModality]));
    app()->forgetScopedInstances();
    $set = factorySetOfPosition(Role::Coordinador);
    app(UpdatePermissionSet::class)->handle($set, ['permissions' => [FakePermission::ViewCampus->value]]);
    app(AssignPermissionSet::class)->handle(Role::JefeDepartamento, $set);

    app()->instance(PermissionRegistry::class, $registry([FakePermission::ViewCampus, FakePermission::ViewModality, FakePermission::ViewCareer]));
    app()->forgetScopedInstances();

    $problem = ['permission_set' => ['«fake.thing.view_career» no se da a Jefe de departamento: el puesto no lleva carrera y el permiso valdría en todas las carreras.']];

    expect(positionActionErrors(fn () => app(ApplyFactoryUpdates::class)->handle($set)))->toBe($problem)
        ->and(positionActionErrors(fn () => app(RestoreFactoryPermissionSet::class)->handle($set)))->toBe($problem)
        ->and(positionSetKeys($set))->toBe([FakePermission::ViewCampus->value]);
});

it('las Actions de los puestos no escriben si fallan a media transacción', function () {
    $set = PermissionSet::factory()->withPermissions([CorePermission::SettingView])->create();

    try {
        DB::transaction(function () use ($set): void {
            app(AssignPermissionSet::class)->handle(Role::Coordinador, $set);

            throw new RuntimeException('revertir');
        });
    } catch (RuntimeException) {
    }

    expect(positionSetNames(Role::Coordinador))->toBe(['Coordinador de carrera (de fábrica)'])
        ->and(Activity::count())->toBe(0)
        ->and(fn () => app(AssignPermissionSet::class)->handle(Role::Admin, $set))->toThrow(ValidationException::class);
});
