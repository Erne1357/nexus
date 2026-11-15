<?php

use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\PermissionSet;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;

/*
| PermissionSetPolicy («Puestos y permisos», 1.22): `core.authz.view` y `core.authz.manage` son candados (CF-03): solo
| el admin ve y edita los roles y los permisos de los puestos. Cada otro puesto tiene su prueba negativa.
*/

function authzHolder(Role $role): User
{
    $campus = Campus::factory()->create();

    return User::factory()->withRole($role, match ($role) {
        Role::Coordinador => ['campus_id' => $campus->id, 'career_id' => careerKey(20)],
        Role::JefeDepartamento => ['department_id' => departmentKey(10)],
        Role::JefeProyectoDocente => ['campus_id' => $campus->id, 'department_id' => departmentKey(10)],
        default => [],
    })->create();
}

/**
 * @return array<string, bool>
 */
function authzAbilities(User $user): array
{
    $set = PermissionSet::query()->where('factory_role', Role::Docente)->sole();

    return [
        'viewAny' => $user->can('viewAny', PermissionSet::class),
        'view' => $user->can('view', $set),
        'create' => $user->can('create', PermissionSet::class),
        'update' => $user->can('update', $set),
        'delete' => $user->can('delete', $set),
        'managePositions' => $user->can('managePositions', PermissionSet::class),
    ];
}

it('CF-03: el admin ve y edita los roles y los permisos de los puestos', function () {
    expect(array_values(array_unique(authzAbilities(User::factory()->admin()->create()))))->toBe([true]);
});

it('CF-03: ningún otro puesto ve ni edita los roles y los permisos', function (Role $role) {
    expect(array_values(array_unique(authzAbilities(authzHolder($role)))))->toBe([false]);
})->with([
    'subdirección' => [Role::Subdireccion],
    'jefe de departamento' => [Role::JefeDepartamento],
    'jefe de proyecto docente' => [Role::JefeProyectoDocente],
    'coordinador' => [Role::Coordinador],
    'docente' => [Role::Docente],
    'alumno' => [Role::Alumno],
]);

it('CF-03: ni con el permiso suelto en la base, otro puesto puede (es candado)', function () {
    DB::table('position_permissions')->insert([
        ['role' => Role::Coordinador->value, 'permission' => CorePermission::AuthzView->value],
        ['role' => Role::Coordinador->value, 'permission' => CorePermission::AuthzManage->value],
    ]);

    expect(array_values(array_unique(authzAbilities(authzHolder(Role::Coordinador)))))->toBe([false]);
});

it('un admin inactivo no puede, ni el admin que actúa con otro perfil', function () {
    $campus = Campus::factory()->create();
    $user = User::factory()->admin()->withRole(Role::Coordinador, ['campus_id' => $campus->id, 'career_id' => careerKey(20)])->create();

    expect(array_values(array_unique(authzAbilities(User::factory()->admin()->create(['is_active' => false])))))->toBe([false]);

    actingAsRole($user, Role::Coordinador);
    expect(array_values(array_unique(authzAbilities($user))))->toBe([false]);

    actingAsRole($user, Role::Admin);
    app()->forgetScopedInstances();
    expect(array_values(array_unique(authzAbilities($user))))->toBe([true]);
});
