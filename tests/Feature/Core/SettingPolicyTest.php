<?php

use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\User;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Settings\SettingDefinition;
use App\Modules\Core\Settings\SettingType;
use Tests\Fixtures\Authorization\FakePermission;

/*
| SettingPolicy (CF-S7, CF-03): el admin ve y cambia los parámetros; ningún otro puesto los tiene de fábrica, pero se pueden
| dar (a diferencia de los candados).
*/

if (! function_exists('grantSettings')) {
    /**
     * Cambia el registro de permisos por el real más una concesión extra a un puesto.
     *
     * @param  list<CorePermission|FakePermission>  $permissions
     */
    function grantSettings(Role $role, array $permissions): void
    {
        $extra = new class(app()) extends ModuleServiceProvider
        {
            /** @var array<string, list<CorePermission>> */
            public array $extra = [];

            public function permissions(): array
            {
                return [FakePermission::class];
            }

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
    'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
    'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
    'docente' => [Role::Docente, []],
]);

it('CF-03: de fábrica Subdirección ve los parámetros pero no los cambia', function () {
    $user = User::factory()->withRole(Role::Subdireccion, ['campus_id' => Campus::factory()->create()->id])->create();

    expect($user->can('viewAny', Setting::class))->toBeTrue()
        ->and($user->can('update', Setting::class))->toBeFalse();
});

it('CF-03: si se le da «ver los parámetros» a un puesto, los ve pero no los cambia', function () {
    grantSettings(Role::Coordinador, [CorePermission::SettingView]);
    $user = User::factory()->withRole(Role::Coordinador, ['campus_id' => Campus::factory()->create()->id, 'career_id' => careerKey(20)])->create();

    expect($user->can('viewAny', Setting::class))->toBeTrue()
        ->and($user->can('update', Setting::class))->toBeFalse();
});

it('CF-03: si se le da «cambiar los parámetros» a un puesto, los cambia', function () {
    grantSettings(Role::Subdireccion, [CorePermission::SettingView, CorePermission::SettingUpdate]);
    $user = User::factory()->withRole(Role::Subdireccion, ['campus_id' => Campus::factory()->create()->id])->create();

    expect($user->can('viewAny', Setting::class))->toBeTrue()
        ->and($user->can('update', Setting::class))->toBeTrue();
});

it('un parámetro que declara un permiso lo deja cambiar a quien lo tiene, y los demás no', function () {
    $owned = new SettingDefinition('fakemod.owned', 'fakemod', 'Prueba', 'Tamaño', 'Ayuda', SettingType::Integer, 5, permission: FakePermission::ViewAny);
    $plain = new SettingDefinition('fakemod.plain', 'fakemod', 'Prueba', 'Otro', 'Ayuda', SettingType::Integer, 5);
    grantSettings(Role::Subdireccion, [FakePermission::ViewAny]);
    $user = User::factory()->withRole(Role::Subdireccion, ['campus_id' => Campus::factory()->create()->id])->create();

    expect($user->can('update', [Setting::class, $owned]))->toBeTrue()
        ->and($user->can('update', [Setting::class, $plain]))->toBeFalse()
        ->and($user->can('update', Setting::class))->toBeFalse();
});

it('niega todo a un admin desactivado', function () {
    $admin = User::factory()->admin()->create(['is_active' => false]);

    expect($admin->can('viewAny', Setting::class))->toBeFalse()
        ->and($admin->can('update', Setting::class))->toBeFalse();
});
