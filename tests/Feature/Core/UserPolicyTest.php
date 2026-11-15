<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;

/*
| UserPolicy: las cuentas de «Personas y puestos» (alta, edición, baja, reactivación, restablecer y dar acceso) son solo
| del admin (`core.user.*`, candados de CF-03): ningún otro puesto ve ni hace nada.
*/

function accountHolder(Role $role): User
{
    $campus = Campus::factory()->create();

    return User::factory()->withRole($role, match ($role) {
        Role::Coordinador => ['campus_id' => $campus->id, 'career_id' => careerKey(20)],
        Role::JefeDepartamento => ['department_id' => departmentKey(10)],
        Role::JefeProyectoDocente => ['campus_id' => $campus->id, 'department_id' => departmentKey(10)],
        default => [],
    })->create();
}

it('CF-03: solo el admin da de alta, edita, da de baja, reactiva, restablece y da acceso', function (Role $role, bool $allowed) {
    $user = accountHolder($role);
    $person = User::factory()->create();

    expect($user->can('create', User::class))->toBe($allowed)
        ->and($user->can('update', $person))->toBe($allowed)
        ->and($user->can('deactivate', $person))->toBe($allowed)
        ->and($user->can('reactivate', $person))->toBe($allowed)
        ->and($user->can('resetPassword', $person))->toBe($allowed)
        ->and($user->can('grantAccess', $person))->toBe($allowed);
})->with([
    'admin' => [Role::Admin, true],
    'subdirección' => [Role::Subdireccion, false],
    'jefe de departamento' => [Role::JefeDepartamento, false],
    'jefe de proyecto docente' => [Role::JefeProyectoDocente, false],
    'coordinador' => [Role::Coordinador, false],
    'docente' => [Role::Docente, false],
    'alumno' => [Role::Alumno, false],
]);

it('un admin inactivo no puede', function () {
    $admin = User::factory()->admin()->create(['is_active' => false]);
    $person = User::factory()->create();

    expect($admin->can('create', User::class))->toBeFalse()
        ->and($admin->can('deactivate', $person))->toBeFalse()
        ->and($admin->can('resetPassword', $person))->toBeFalse();
});

it('la misma persona con Coordinación activa no puede aunque también sea admin', function () {
    $campus = Campus::factory()->create();
    $user = User::factory()->admin()->withRole(Role::Coordinador, ['campus_id' => $campus->id, 'career_id' => careerKey(20)])->create();
    $person = User::factory()->create();

    actingAsRole($user, Role::Admin);
    expect($user->can('deactivate', $person))->toBeTrue();

    actingAsRole($user, Role::Coordinador);
    expect($user->can('create', User::class))->toBeFalse()
        ->and($user->can('update', $person))->toBeFalse()
        ->and($user->can('deactivate', $person))->toBeFalse()
        ->and($user->can('reactivate', $person))->toBeFalse()
        ->and($user->can('resetPassword', $person))->toBeFalse();
});
