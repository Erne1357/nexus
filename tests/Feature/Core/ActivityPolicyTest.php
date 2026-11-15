<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Spatie\Activitylog\Models\Activity;

/*
| ActivityPolicy (CF-S5, CF-03): `core.activity.view` es un candado, solo del admin; ningún otro puesto ve la bitácora.
| No hay ability para editar ni borrar: la bitácora es inmutable (HC-18).
*/

it('CF-03: el admin ve la bitácora y cada entrada', function () {
    $admin = User::factory()->admin()->create();
    $entry = activity()->log('Algo pasó');

    expect($admin->can('viewAny', Activity::class))->toBeTrue()
        ->and($admin->can('view', $entry))->toBeTrue();
});

it('CF-03: ningún otro puesto ve la bitácora', function (Role $role, array $scope) {
    $user = User::factory()->withRole($role, ['campus_id' => Campus::factory()->create()->id, ...$scope])->create();
    $entry = activity()->log('Algo pasó');

    expect($user->can('viewAny', Activity::class))->toBeFalse()
        ->and($user->can('view', $entry))->toBeFalse();
})->with([
    'subdirección' => [Role::Subdireccion, []],
    'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
    'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
    'jefe de proyecto docente' => [Role::JefeProyectoDocente, fn () => ['department_id' => departmentKey(10)]],
    'docente' => [Role::Docente, []],
]);

it('CF-03: niega todo a un admin desactivado', function () {
    $admin = User::factory()->admin()->create(['is_active' => false]);

    expect($admin->can('viewAny', Activity::class))->toBeFalse();
});

it('HC-18: la bitácora no tiene abilities para editar ni borrar, ni para el admin', function () {
    $admin = User::factory()->admin()->create();
    $entry = activity()->log('Algo pasó');

    expect($admin->can('update', $entry))->toBeFalse()
        ->and($admin->can('delete', $entry))->toBeFalse();
});
