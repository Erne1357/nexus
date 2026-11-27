<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\User;

/*
| OrgUnitPolicy: ver, dar de alta, editar, mover, desactivar y activar unidades del organigrama son solo del admin
| (`core.org_unit.*`, spec §6.1). Las reglas de cada operación las deciden OrgUnitRules y las Actions.
*/

function orgUnitHolder(Role $role): User
{
    $campus = Campus::factory()->create();

    return User::factory()->withRole($role, match ($role) {
        Role::Coordinador => ['campus_id' => $campus->id, 'career_id' => careerKey(20)],
        Role::JefeDepartamento => ['department_id' => departmentKey(10)],
        Role::JefeProyectoDocente => ['campus_id' => $campus->id, 'department_id' => departmentKey(10)],
        default => [],
    })->create();
}

it('solo el admin ve, crea, edita, mueve, desactiva y activa', function (Role $role, bool $allowed) {
    $user = orgUnitHolder($role);
    $unit = OrgUnit::factory()->create();

    expect($user->can('viewAny', OrgUnit::class))->toBe($allowed)
        ->and($user->can('create', OrgUnit::class))->toBe($allowed)
        ->and($user->can('update', $unit))->toBe($allowed)
        ->and($user->can('move', $unit))->toBe($allowed)
        ->and($user->can('setActive', $unit))->toBe($allowed);
})->with([
    'admin' => [Role::Admin, true],
    'subdirección' => [Role::Subdireccion, false],
    'jefe de departamento' => [Role::JefeDepartamento, false],
    'jefe de proyecto docente' => [Role::JefeProyectoDocente, false],
    'coordinador' => [Role::Coordinador, false],
    'docente' => [Role::Docente, false],
]);

it('un admin inactivo no puede', function () {
    $admin = User::factory()->admin()->create(['is_active' => false]);
    $unit = OrgUnit::factory()->create();

    expect($admin->can('viewAny', OrgUnit::class))->toBeFalse()
        ->and($admin->can('create', OrgUnit::class))->toBeFalse()
        ->and($admin->can('update', $unit))->toBeFalse()
        ->and($admin->can('move', $unit))->toBeFalse()
        ->and($admin->can('setActive', $unit))->toBeFalse();
});

it('la misma persona con Coordinación activa no puede aunque también sea admin', function () {
    $campus = Campus::factory()->create();
    $user = User::factory()->admin()
        ->withRole(Role::Coordinador, ['campus_id' => $campus->id, 'career_id' => careerKey(20)])->create();
    $unit = OrgUnit::factory()->create();

    actingAsRole($user, Role::Admin);
    expect($user->can('viewAny', OrgUnit::class))->toBeTrue()
        ->and($user->can('move', $unit))->toBeTrue();

    actingAsRole($user, Role::Coordinador);
    expect($user->can('viewAny', OrgUnit::class))->toBeFalse()
        ->and($user->can('create', OrgUnit::class))->toBeFalse()
        ->and($user->can('update', $unit))->toBeFalse()
        ->and($user->can('move', $unit))->toBeFalse()
        ->and($user->can('setActive', $unit))->toBeFalse();
});
