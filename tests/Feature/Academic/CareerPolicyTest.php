<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;

/*
| CareerPolicy. Según reglas-de-negocio.md, "Aprobaciones y permisos" (fila "Administrar
| catálogos de Academic": `admin`), solo el admin ve, da de alta, edita y borra carreras; en la
| fase 2 se abrirán a los jefes para validar.
*/

beforeEach(function () {
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->career = Career::factory()->create();
});

it('al admin le permite todo, también sobre una carrera inactiva', function () {
    $admin = User::factory()->admin()->create();
    $inactive = Career::factory()->inactive()->create();

    expect($admin->can('viewAny', Career::class))->toBeTrue()
        ->and($admin->can('create', Career::class))->toBeTrue();

    foreach ([$this->career, $inactive] as $career) {
        expect($admin->can('view', $career))->toBeTrue()
            ->and($admin->can('update', $career))->toBeTrue()
            ->and($admin->can('delete', $career))->toBeTrue();
    }
});

it('niega todo a la subdirección, al jefe, al coordinador y al docente de C2', function (Role $role, array $scope) {
    $user = User::factory()->withRole($role, ['campus_id' => $this->c2->id, ...$scope])->create();

    expect($user->can('viewAny', Career::class))->toBeFalse()
        ->and($user->can('create', Career::class))->toBeFalse()
        ->and($user->can('view', $this->career))->toBeFalse()
        ->and($user->can('update', $this->career))->toBeFalse()
        ->and($user->can('delete', $this->career))->toBeFalse();
})->with([
    'subdirección' => [Role::Subdireccion, []],
    'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
    'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
    'docente' => [Role::Docente, []],
]);

it('niega todo a un admin desactivado', function () {
    $admin = User::factory()->admin()->create(['is_active' => false]);

    expect($admin->can('viewAny', Career::class))->toBeFalse()
        ->and($admin->can('create', Career::class))->toBeFalse()
        ->and($admin->can('update', $this->career))->toBeFalse()
        ->and($admin->can('delete', $this->career))->toBeFalse();
});
