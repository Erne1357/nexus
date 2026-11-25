<?php

use App\Modules\Core\Actions\GrantPositionPermissions;
use App\Modules\Core\Authorization\GrantTable;
use App\Modules\Core\Authorization\PermissionGrantRules;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\Role;
use App\Modules\Faculty\Enums\FacultyPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| GrantPositionPermissions: «Agregar permisos sueltos» da varios a un puesto en una sola transacción (CF-01), todos o
| ninguno, con una entrada de bitácora por cada uno. Las mismas reglas que GrantPositionPermission: el admin no se edita
| (CF-S1), ningún candado (CF-03) ni nada que amplíe el alcance del puesto (CF-04).
*/

/**
 * @return array<string, list<string>>
 */
function batchGrantErrors(Closure $action): array
{
    try {
        $action();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

/**
 * @return list<string>
 */
function batchLooseOf(Role $role): array
{
    return DB::table('position_permissions')->where('role', $role->value)->orderBy('permission')->pluck('permission')->all();
}

it('CF-01: da varios permisos sueltos en una sola transacción, con una entrada de bitácora por cada uno', function () {
    DB::table('position_permissions')->insert(['role' => Role::Coordinador->value, 'permission' => CorePermission::TermView->value]);

    app(GrantPositionPermissions::class)->handle(Role::Coordinador, [CorePermission::SettingView, CorePermission::TermView, CorePermission::CampusViewInactive]);

    expect(batchLooseOf(Role::Coordinador))->toBe(['core.campus.view_inactive', 'core.setting.view', 'core.term.view'])
        ->and(app(GrantTable::class)->grants(Role::Coordinador, CorePermission::SettingView))->toBeTrue()
        ->and(Activity::query()->orderBy('id')->pluck('description')->all())->toBe([
            'Dio el permiso «Ver campus inactivos» al puesto Coordinador de carrera',
            'Dio el permiso «Ver los parámetros» al puesto Coordinador de carrera',
        ])
        ->and(Activity::query()->latest('id')->first()->attribute_changes->toArray()['attributes']['permissions'])->toBe(['core.campus.view_inactive', 'core.setting.view', 'core.term.view']);
});

it('CF-03 y CF-04: si alguno no se puede dar, no da ninguno y dice todos los motivos', function () {
    $rules = app(PermissionGrantRules::class);

    expect(batchGrantErrors(fn () => app(GrantPositionPermissions::class)->handle(Role::Coordinador, [CorePermission::SettingView, FacultyPermission::TeacherView, CorePermission::UserCreate])))
        ->toBe(['permissions' => [$rules->lockProblem(CorePermission::UserCreate), $rules->grantProblem(Role::Coordinador, FacultyPermission::TeacherView)]])
        ->and(batchLooseOf(Role::Coordinador))->toBe([])
        ->and(Activity::count())->toBe(0);
});

it('CF-S1: el admin no se edita', function () {
    expect(batchGrantErrors(fn () => app(GrantPositionPermissions::class)->handle(Role::Admin, [CorePermission::SettingView])))->toHaveKey('role')
        ->and(batchLooseOf(Role::Admin))->toBe([]);
});
