<?php

use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\LoginNotice;
use App\Modules\Core\Models\LoginShortcut;
use App\Modules\Core\Models\User;
use App\Modules\Core\ModuleServiceProvider;

/*
| Policies de avisos y atajos de la pantalla de acceso (CF-S6, CF-03): el admin los maneja; ningún otro puesto los tiene
| de fábrica, pero se pueden dar (a diferencia de los candados) y entonces los puestos con el permiso sí.
*/

if (! function_exists('grantLoginContent')) {
    /**
     * Cambia el registro de permisos por el real más una concesión extra a un puesto.
     *
     * @param  list<CorePermission>  $permissions
     */
    function grantLoginContent(Role $role, array $permissions): void
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

it('CF-S6: el admin ve, da de alta, edita y borra avisos y atajos', function () {
    $admin = User::factory()->admin()->create();
    $notice = LoginNotice::factory()->create();
    $shortcut = LoginShortcut::factory()->create();

    expect($admin->can('viewAny', LoginNotice::class))->toBeTrue()
        ->and($admin->can('create', LoginNotice::class))->toBeTrue()
        ->and($admin->can('update', $notice))->toBeTrue()
        ->and($admin->can('delete', $notice))->toBeTrue()
        ->and($admin->can('viewAny', LoginShortcut::class))->toBeTrue()
        ->and($admin->can('create', LoginShortcut::class))->toBeTrue()
        ->and($admin->can('update', $shortcut))->toBeTrue()
        ->and($admin->can('delete', $shortcut))->toBeTrue();
});

it('CF-S6: de fábrica ningún otro puesto ve ni maneja avisos ni atajos', function (Role $role, array $scope) {
    $campus = Campus::factory()->create();
    $user = User::factory()->withRole($role, ['campus_id' => $campus->id, ...$scope])->create();
    $notice = LoginNotice::factory()->create();
    $shortcut = LoginShortcut::factory()->create();

    expect($user->can('viewAny', LoginNotice::class))->toBeFalse()
        ->and($user->can('create', LoginNotice::class))->toBeFalse()
        ->and($user->can('update', $notice))->toBeFalse()
        ->and($user->can('delete', $notice))->toBeFalse()
        ->and($user->can('viewAny', LoginShortcut::class))->toBeFalse()
        ->and($user->can('create', LoginShortcut::class))->toBeFalse()
        ->and($user->can('update', $shortcut))->toBeFalse()
        ->and($user->can('delete', $shortcut))->toBeFalse();
})->with([
    'subdirección' => [Role::Subdireccion, []],
    'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
    'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
    'docente' => [Role::Docente, []],
]);

it('CF-03: si se le da «ver avisos» a un puesto, ve pero no maneja avisos, ni toca los atajos', function () {
    grantLoginContent(Role::Subdireccion, [CorePermission::LoginNoticeView]);
    $user = User::factory()->withRole(Role::Subdireccion, ['campus_id' => Campus::factory()->create()->id])->create();

    expect($user->can('viewAny', LoginNotice::class))->toBeTrue()
        ->and($user->can('create', LoginNotice::class))->toBeFalse()
        ->and($user->can('update', LoginNotice::factory()->create()))->toBeFalse()
        ->and($user->can('viewAny', LoginShortcut::class))->toBeFalse();
});

it('CF-03: si se le da «manejar atajos» a un puesto, maneja atajos pero no avisos', function () {
    grantLoginContent(Role::Subdireccion, [CorePermission::LoginShortcutManage]);
    $user = User::factory()->withRole(Role::Subdireccion, ['campus_id' => Campus::factory()->create()->id])->create();

    expect($user->can('create', LoginShortcut::class))->toBeTrue()
        ->and($user->can('update', LoginShortcut::factory()->create()))->toBeTrue()
        ->and($user->can('delete', LoginShortcut::factory()->create()))->toBeTrue()
        ->and($user->can('create', LoginNotice::class))->toBeFalse()
        ->and($user->can('delete', LoginNotice::factory()->create()))->toBeFalse();
});

it('niega todo a un admin desactivado', function () {
    $admin = User::factory()->admin()->create(['is_active' => false]);

    expect($admin->can('viewAny', LoginNotice::class))->toBeFalse()
        ->and($admin->can('create', LoginShortcut::class))->toBeFalse();
});
