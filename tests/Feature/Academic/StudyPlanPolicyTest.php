<?php

use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;

/*
| StudyPlanPolicy. Según reglas-de-negocio.md, "Aprobaciones y permisos" (fila "Administrar
| catálogos de Academic": `admin`), solo el admin ve, da de alta, edita, activa, desactiva y edita la
| retícula de los planes.
*/

beforeEach(function () {
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->plan = StudyPlan::factory()->create();
});

it('al admin le permite todo, también sobre un plan inactivo', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->can('viewAny', StudyPlan::class))->toBeTrue()
        ->and($admin->can('create', StudyPlan::class))->toBeTrue();

    foreach ([$this->plan, StudyPlan::factory()->inactive()->create()] as $plan) {
        expect($admin->can('view', $plan))->toBeTrue()
            ->and($admin->can('update', $plan))->toBeTrue()
            ->and($admin->can('editGrid', $plan))->toBeTrue();
    }
});

it('niega todo a la subdirección, al jefe, al coordinador y al docente de C2', function (Role $role, array $scope) {
    $user = User::factory()->withRole($role, ['campus_id' => $this->c2->id, ...$scope])->create();

    expect($user->can('viewAny', StudyPlan::class))->toBeFalse()
        ->and($user->can('create', StudyPlan::class))->toBeFalse()
        ->and($user->can('view', $this->plan))->toBeFalse()
        ->and($user->can('update', $this->plan))->toBeFalse()
        ->and($user->can('editGrid', $this->plan))->toBeFalse();
})->with([
    'subdirección' => [Role::Subdireccion, []],
    'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
    'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
    'docente' => [Role::Docente, []],
]);

it('niega todo a un admin desactivado', function () {
    $admin = User::factory()->admin()->create(['is_active' => false]);

    expect($admin->can('viewAny', StudyPlan::class))->toBeFalse()
        ->and($admin->can('update', $this->plan))->toBeFalse()
        ->and($admin->can('editGrid', $this->plan))->toBeFalse();
});

it('no hay ability para borrar: los planes no se borran, se desactivan', function () {
    expect(User::factory()->admin()->create()->can('delete', $this->plan))->toBeFalse();
});
