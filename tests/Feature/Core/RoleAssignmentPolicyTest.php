<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;

/*
| RoleAssignmentPolicy: ver personas y puestos, nombrar y terminar nombramientos son solo del admin
| (`core.assignment.*`, reglas-de-negocio.md, "Aprobaciones y permisos", confirmado el 2 de noviembre de 2026).
*/

function assignmentHolder(Role $role): User
{
    $campus = Campus::factory()->create();

    return User::factory()->withRole($role, match ($role) {
        Role::Coordinador => ['campus_id' => $campus->id, 'career_id' => careerKey(20)],
        Role::JefeDepartamento => ['department_id' => departmentKey(10)],
        Role::JefeProyectoDocente => ['campus_id' => $campus->id, 'department_id' => departmentKey(10)],
        default => [],
    })->create();
}

it('solo el admin ve, nombra y termina', function (Role $role, bool $allowed) {
    $user = assignmentHolder($role);
    $assignment = RoleAssignment::factory()->create();

    expect($user->can('viewAny', RoleAssignment::class))->toBe($allowed)
        ->and($user->can('create', RoleAssignment::class))->toBe($allowed)
        ->and($user->can('end', $assignment))->toBe($allowed);
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
    $assignment = RoleAssignment::factory()->create();

    expect($admin->can('viewAny', RoleAssignment::class))->toBeFalse()
        ->and($admin->can('create', RoleAssignment::class))->toBeFalse()
        ->and($admin->can('end', $assignment))->toBeFalse();
});

it('la misma persona con Coordinación activa no puede aunque también sea admin', function () {
    $campus = Campus::factory()->create();
    $user = User::factory()->admin()
        ->withRole(Role::Coordinador, ['campus_id' => $campus->id, 'career_id' => careerKey(20)])->create();
    $assignment = RoleAssignment::factory()->create();

    actingAsRole($user, Role::Admin);
    expect($user->can('viewAny', RoleAssignment::class))->toBeTrue()
        ->and($user->can('end', $assignment))->toBeTrue();

    actingAsRole($user, Role::Coordinador);
    expect($user->can('viewAny', RoleAssignment::class))->toBeFalse()
        ->and($user->can('create', RoleAssignment::class))->toBeFalse()
        ->and($user->can('end', $assignment))->toBeFalse();
});
