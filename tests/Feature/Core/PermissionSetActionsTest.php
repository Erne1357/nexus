<?php

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\Core\Actions\CreatePermissionSet;
use App\Modules\Core\Actions\DeletePermissionSet;
use App\Modules\Core\Actions\SetPermissionSetActive;
use App\Modules\Core\Actions\UpdatePermissionSet;
use App\Modules\Core\Authorization\GrantTable;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\PermissionSet;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Enums\FacultyPermission;
use App\Modules\Staffing\Enums\StaffingPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Las Actions de los roles (CF-01, CF-02, CF-03, CF-04 y CF-S2): alta, cambio, activar o desactivar y borrar. Cada
| una escribe en una transacción y deja en la bitácora lo de antes y lo de después, con las claves de los permisos.
*/

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

/**
 * Los mensajes de la ValidationException que lanza `$action`, por campo.
 *
 * @return array<string, list<string>>
 */
function permissionSetErrors(Closure $action): array
{
    try {
        $action();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('No lanzó ValidationException.');
}

function factorySetFor(Role $role): PermissionSet
{
    return PermissionSet::query()->where('factory_role', $role->value)->sole();
}

/**
 * @return list<string>
 */
function ownKeysOf(PermissionSet $set): array
{
    return DB::table('permission_set_permissions')->where('permission_set_id', $set->id)->orderBy('permission')->pluck('permission')->all();
}

it('CF-S2: da de alta un rol con nombre, descripción y permisos, y lo deja en la bitácora', function () {
    $set = app(CreatePermissionSet::class)->handle('  Calendario   del periodo ', '  Ver y manejar el calendario ', [
        CorePermission::TermManageCalendar->value, CorePermission::TermView->value, CorePermission::TermView->value,
    ]);

    expect($set->name)->toBe('Calendario del periodo')
        ->and($set->description)->toBe('Ver y manejar el calendario')
        ->and($set->factory_role)->toBeNull()
        ->and($set->follows_factory)->toBeFalse()
        ->and(ownKeysOf($set))->toBe(['core.term.manage_calendar', 'core.term.view'])
        ->and(Activity::sole()->attribute_changes->toArray())->toBe(['attributes' => [
            'name' => 'Calendario del periodo',
            'description' => 'Ver y manejar el calendario',
            'permissions' => ['core.term.manage_calendar', 'core.term.view'],
        ]])
        ->and(Activity::sole()->subject->is($set))->toBeTrue();
});

it('CF-S2: el nombre es único sin importar mayúsculas entre los no borrados, y tiene un largo máximo', function () {
    app(CreatePermissionSet::class)->handle('Calendario', null, [CorePermission::TermView->value]);
    $deleted = app(CreatePermissionSet::class)->handle('Avisos', null, [CorePermission::LoginNoticeView->value]);
    app(DeletePermissionSet::class)->handle($deleted);

    expect(permissionSetErrors(fn () => app(CreatePermissionSet::class)->handle('CALENDARIO', null, [CorePermission::TermView->value])))
        ->toBe(['name' => ['Ya hay un rol que se llama «CALENDARIO».']])
        ->and(permissionSetErrors(fn () => app(CreatePermissionSet::class)->handle('   ', null, [CorePermission::TermView->value])))
        ->toBe(['name' => ['Escribe el nombre del rol.']])
        ->and(permissionSetErrors(fn () => app(CreatePermissionSet::class)->handle(str_repeat('a', 81), null, [CorePermission::TermView->value])))
        ->toBe(['name' => ['El nombre del rol lleva a lo más 80 caracteres.']])
        ->and(permissionSetErrors(fn () => app(CreatePermissionSet::class)->handle('Otro', str_repeat('a', 241), [CorePermission::TermView->value])))
        ->toBe(['description' => ['La descripción lleva a lo más 240 caracteres.']])
        ->and(app(CreatePermissionSet::class)->handle('avisos', null, [CorePermission::LoginNoticeView->value])->name)->toBe('avisos');
});

it('CF-S2 y CF-03: un rol lleva al menos un permiso declarado y ningún candado', function () {
    expect(permissionSetErrors(fn () => app(CreatePermissionSet::class)->handle('Vacío', null, [])))
        ->toBe(['permissions' => ['Elige al menos un permiso.']])
        ->and(permissionSetErrors(fn () => app(CreatePermissionSet::class)->handle('Panel', null, [CorePermission::ActivityView->value, 'nadie.declaro.esto', CorePermission::SettingView->value])))
        ->toBe(['permissions' => ['«Ver la bitácora» es solo del admin: no se da a otro puesto.', 'El permiso «nadie.declaro.esto» no existe.']])
        ->and(PermissionSet::query()->whereNull('factory_role')->exists())->toBeFalse()
        ->and(Activity::count())->toBe(0);
});

it('CF-02: editar los permisos de un rol de fábrica lo deja de seguir al código, con su línea base', function () {
    $set = factorySetFor(Role::Docente);
    $factory = app(PermissionRegistry::class)->matrix()[Role::Docente->value];

    app(UpdatePermissionSet::class)->handle($set, ['permissions' => [StaffingPermission::MyLoadView->value]]);

    expect($set->fresh()->follows_factory)->toBeFalse()
        ->and($set->fresh()->factory_baseline)->toBe($factory)
        ->and(ownKeysOf($set))->toBe([StaffingPermission::MyLoadView->value])
        ->and(app(GrantTable::class)->matrix()[Role::Docente->value])->toBe([StaffingPermission::MyLoadView->value])
        ->and(Activity::sole()->attribute_changes->toArray())->toBe([
            'attributes' => ['permissions' => [StaffingPermission::MyLoadView->value], 'follows_factory' => false],
            'old' => ['permissions' => $factory, 'follows_factory' => true],
        ]);
});

it('CF-S2: una clave que el rol tenía y que ningún módulo declara ya se descarta al guardar, en lugar de bloquearlo', function () {
    $set = PermissionSet::factory()->withPermissions([CorePermission::SettingView, 'scheduling.room.reserve'])->create();

    app(UpdatePermissionSet::class)->handle($set, ['permissions' => [CorePermission::SettingView->value, 'scheduling.room.reserve', CorePermission::LoginNoticeView->value]]);

    expect(ownKeysOf($set))->toBe([CorePermission::LoginNoticeView->value, CorePermission::SettingView->value])
        ->and(permissionSetErrors(fn () => app(UpdatePermissionSet::class)->handle($set, ['permissions' => ['otra.clave.nueva']])))
        ->toBe(['permissions' => ['El permiso «otra.clave.nueva» no existe.']]);
});

it('CF-02: cambiar solo el nombre de un rol de fábrica no lo deja de seguir al código; sin cambios no registra', function () {
    $set = factorySetFor(Role::Docente);

    app(UpdatePermissionSet::class)->handle($set, ['name' => 'Docente', 'permissions' => app(PermissionRegistry::class)->matrix()[Role::Docente->value]]);
    app(UpdatePermissionSet::class)->handle($set, ['name' => 'Docente', 'description' => '']);

    expect($set->fresh()->name)->toBe('Docente')
        ->and($set->fresh()->follows_factory)->toBeTrue()
        ->and(ownKeysOf($set))->toBe([])
        ->and(Activity::sole()->attribute_changes->toArray())->toBe([
            'attributes' => ['name' => 'Docente'],
            'old' => ['name' => 'Docente (de fábrica)'],
        ]);
});

it('CF-04: a un rol ya dado a un puesto no se le agrega lo que ampliaría su alcance', function () {
    $set = PermissionSet::factory()->withPermissions([CorePermission::TermView])->assignedTo(Role::Coordinador, Role::Docente)->create();

    expect(permissionSetErrors(fn () => app(UpdatePermissionSet::class)->handle($set, ['permissions' => [CorePermission::TermView->value, FacultyPermission::TeacherView->value]])))
        ->toBe(['permissions' => [
            '«Ver docentes» no se da a Coordinador de carrera: el puesto no lleva departamento y el permiso valdría en todos los departamentos.',
            '«Ver docentes» no se da a Docente: el puesto no lleva alcance y solo recibe permisos personales.',
        ]])
        ->and(ownKeysOf($set))->toBe([CorePermission::TermView->value]);
});

it('CF-04: lo de fábrica de su propio puesto sí se agrega al rol de fábrica editado', function () {
    $set = factorySetFor(Role::Coordinador);
    $factory = app(PermissionRegistry::class)->matrix()[Role::Coordinador->value];

    app(UpdatePermissionSet::class)->handle($set, ['permissions' => [AcademicLoadPermission::ProposalView->value]]);
    app(UpdatePermissionSet::class)->handle($set, ['permissions' => $factory]);

    expect(ownKeysOf($set))->toBe($factory);
});

it('CF-S2: un rol propio se desactiva y se activa; el de fábrica no se desactiva', function () {
    $set = PermissionSet::factory()->withPermissions([CorePermission::SettingView])->assignedTo(Role::Coordinador)->create();

    app(SetPermissionSetActive::class)->handle($set, false);

    expect($set->fresh()->is_active)->toBeFalse()
        ->and(app(GrantTable::class)->grants(Role::Coordinador, CorePermission::SettingView))->toBeFalse()
        ->and(Activity::sole()->attribute_changes->toArray())->toBe(['attributes' => ['is_active' => false], 'old' => ['is_active' => true]]);

    app(SetPermissionSetActive::class)->handle($set, true);
    app(SetPermissionSetActive::class)->handle($set, true);

    expect(app(GrantTable::class)->grants(Role::Coordinador, CorePermission::SettingView))->toBeTrue()
        ->and(Activity::count())->toBe(2)
        ->and(permissionSetErrors(fn () => app(SetPermissionSetActive::class)->handle(factorySetFor(Role::Docente), false)))
        ->toBe(['permission_set' => ['«Docente (de fábrica)» es un rol de fábrica: no se desactiva.']]);
});

it('CF-S2: un rol se borra (soft delete) solo si ningún puesto lo tiene, y nunca el de fábrica', function () {
    $assigned = PermissionSet::factory()->withPermissions([CorePermission::SettingView])->assignedTo(Role::Coordinador, Role::JefeDepartamento)->create(['name' => 'Parámetros']);
    $free = PermissionSet::factory()->withPermissions([CorePermission::SettingView])->create(['name' => 'Libre']);

    expect(permissionSetErrors(fn () => app(DeletePermissionSet::class)->handle($assigned)))
        ->toBe(['permission_set' => ['Quita «Parámetros» de los puestos que lo tienen antes de borrarlo: Jefe de departamento, Coordinador de carrera.']])
        ->and(permissionSetErrors(fn () => app(DeletePermissionSet::class)->handle(factorySetFor(Role::Alumno))))
        ->toBe(['permission_set' => ['«Alumno (de fábrica)» es un rol de fábrica: no se borra.']]);

    app(DeletePermissionSet::class)->handle($free);

    expect($free->fresh()->trashed())->toBeTrue()
        ->and(ownKeysOf($free))->toBe([CorePermission::SettingView->value])
        ->and(Activity::sole()->attribute_changes->toArray())->toBe(['old' => ['name' => 'Libre', 'permissions' => [CorePermission::SettingView->value]]]);
});

it('CF-01: lo que cambia una Action vale en la misma petición después de ella y sube la versión', function () {
    $version = app(GrantTable::class)->version();
    $set = PermissionSet::factory()->withPermissions([CorePermission::SettingView])->assignedTo(Role::Coordinador)->create();

    expect(app(GrantTable::class)->version())->toBe($version);

    app(UpdatePermissionSet::class)->handle($set, ['permissions' => [CorePermission::SettingView->value, CorePermission::LoginNoticeView->value]]);

    expect(app(GrantTable::class)->version())->toBeGreaterThan($version)
        ->and(app(GrantTable::class)->grants(Role::Coordinador, CorePermission::LoginNoticeView))->toBeTrue();
});
