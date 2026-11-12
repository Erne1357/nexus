<?php

use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;

/*
| AcademicLoadPolicy. Según reglas-de-negocio.md, "Aprobaciones y permisos": ven la carga la subdirección,
| el jefe de departamento y el jefe de proyecto docente de su campus y departamento; la propone el jefe de
| departamento (S-A9, supuesto por confirmar). El admin, siempre.
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->load = AcademicLoad::factory()->create(['campus_id' => $this->c1->id, 'department_id' => departmentKey(10)]);
});

it('permite según el rol y su alcance en la propia carga', function (Role $role, array $scope, bool $view, bool $propose) {
    $user = User::factory()->withRole($role, ['campus_id' => $this->c1->id, ...$scope])->create();

    expect($user->can('view', $this->load))->toBe($view)
        ->and($user->can('propose', $this->load))->toBe($propose);
})->with([
    'subdirección' => [Role::Subdireccion, [], true, false],
    'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)], true, true],
    'jefe de proyecto docente' => [Role::JefeProyectoDocente, fn () => ['department_id' => departmentKey(10)], true, false],
    'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)], false, false],
    'docente' => [Role::Docente, [], false, false],
]);

it('al admin le permite ver y proponer cualquier carga', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->can('view', $this->load))->toBeTrue()
        ->and($admin->can('propose', $this->load))->toBeTrue();
});

it('niega al jefe de otro departamento', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->c1->id, 'department_id' => departmentKey(11)])->create();

    expect($jefe->can('view', $this->load))->toBeFalse()
        ->and($jefe->can('propose', $this->load))->toBeFalse();
});

it('AZ-S17: el jefe de proyecto docente y el jefe de departamento de otro campus ven la carga de su modalidad', function (Role $role) {
    $user = User::factory()->withRole($role, ['campus_id' => $this->c2->id, 'department_id' => departmentKey(10)])->create();

    expect($user->can('view', $this->load))->toBeTrue();
})->with([Role::JefeProyectoDocente, Role::JefeDepartamento]);

it('AZ-S17: niega al jefe de proyecto docente y al jefe de departamento de otra modalidad', function (Role $role) {
    modalityOf('EAD')->update(['is_active' => true]);
    $user = User::factory()->withRole($role, ['modality_id' => modalityOf('EAD')->id, 'department_id' => departmentKey(10)])->create();

    expect($user->can('view', $this->load))->toBeFalse()
        ->and($user->can('propose', $this->load))->toBeFalse();
})->with([Role::JefeProyectoDocente, Role::JefeDepartamento]);

it('niega todo a un admin desactivado', function () {
    $admin = User::factory()->admin()->create(['is_active' => false]);

    expect($admin->can('view', $this->load))->toBeFalse()
        ->and($admin->can('propose', $this->load))->toBeFalse();
});

it('la misma persona con otro perfil activo es rechazada', function () {
    $jefe = User::factory()
        ->withRole(Role::JefeDepartamento, ['campus_id' => $this->c1->id, 'department_id' => departmentKey(10)])
        ->withRole(Role::Coordinador, ['campus_id' => $this->c1->id, 'career_id' => careerKey(20)])
        ->create();

    actingAsRole($jefe, Role::JefeDepartamento);
    expect($jefe->can('view', $this->load))->toBeTrue()
        ->and($jefe->can('propose', $this->load))->toBeTrue();

    actingAsRole($jefe, Role::Coordinador);
    expect($jefe->can('view', $this->load))->toBeFalse()
        ->and($jefe->can('propose', $this->load))->toBeFalse();
});
