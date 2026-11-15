<?php

use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\User;
use App\Modules\Core\ModuleServiceProvider;

/*
| SettingPolicy (CF-S7, CF-03): el admin ve y cambia los parámetros; ningún otro puesto los tiene de fábrica, pero se pueden
| dar (a diferencia de los candados).
*/

if (! function_exists('grantSettings')) {
    /**
     * Cambia el registro de permisos por el real más una concesión extra a un puesto.
     *
     * @param  list<CorePermission>  $permissions
     */
    function grantSettings(Role $role, array $permissions): void
    {
        $extra = new class(app()) extends ModuleServiceProvider
        {
            /** @var array<string, list<CorePermission>> */
            public array $extra = [];

            public function grants(): array
            {
                return $this->extra;
            }
        };
        $extra->extra = [$role->value => $permissions];

        app()->instance(PermissionRegistry::class, PermissionRegistry::fromModules([...app()->getProviders(ModuleServiceProvider::class), $extra]));
        app()->forgetScopedInstances();
    }
}

it('CF-S7: el admin ve y cambia los parámetros', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->can('viewAny', Setting::class))->toBeTrue()
        ->and($admin->can('update', Setting::class))->toBeTrue();
});

it('CF-S7: de fábrica ningún otro puesto ve ni cambia los parámetros', function (Role $role, array $scope) {
    $user = User::factory()->withRole($role, ['campus_id' => Campus::factory()->create()->id, ...$scope])->create();

    expect($user->can('viewAny', Setting::class))->toBeFalse()
        ->and($user->can('update', Setting::class))->toBeFalse();
})->with([
    'subdirección' => [Role::Subdireccion, []],
    'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
    'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
    'docente' => [Role::Docente, []],
]);

it('CF-03: si se le da «ver los parámetros» a un puesto, los ve pero no los cambia', function () {
    grantSettings(Role::Subdireccion, [CorePermission::SettingView]);
    $user = User::factory()->withRole(Role::Subdireccion, ['campus_id' => Campus::factory()->create()->id])->create();

    expect($user->can('viewAny', Setting::class))->toBeTrue()
        ->and($user->can('update', Setting::class))->toBeFalse();
});

it('CF-03: si se le da «cambiar los parámetros» a un puesto, los cambia', function () {
    grantSettings(Role::Subdireccion, [CorePermission::SettingView, CorePermission::SettingUpdate]);
    $user = User::factory()->withRole(Role::Subdireccion, ['campus_id' => Campus::factory()->create()->id])->create();

    expect($user->can('viewAny', Setting::class))->toBeTrue()
        ->and($user->can('update', Setting::class))->toBeTrue();
});

it('niega todo a un admin desactivado', function () {
    $admin = User::factory()->admin()->create(['is_active' => false]);

    expect($admin->can('viewAny', Setting::class))->toBeFalse()
        ->and($admin->can('update', Setting::class))->toBeFalse();
});
