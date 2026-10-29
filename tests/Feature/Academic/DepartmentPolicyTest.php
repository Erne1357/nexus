<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;

/*
| DepartmentPolicy. Según reglas-de-negocio.md, "Aprobaciones y permisos" (fila "Administrar
| catálogos de Academic": `admin`), solo el admin ve, da de alta, edita y borra departamentos; en la
| fase 2 se abrirán a los jefes para validar.
*/

beforeEach(function () {
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->department = Department::factory()->create();
});

it('al admin le permite todo, también sobre un departamento inactivo', function () {
    $admin = User::factory()->admin()->create();
    $inactive = Department::factory()->inactive()->create();

    expect($admin->can('viewAny', Department::class))->toBeTrue()
        ->and($admin->can('create', Department::class))->toBeTrue();

    foreach ([$this->department, $inactive] as $department) {
        expect($admin->can('view', $department))->toBeTrue()
            ->and($admin->can('update', $department))->toBeTrue()
            ->and($admin->can('delete', $department))->toBeTrue();
    }
});

it('niega todo a la subdirección, al jefe, al coordinador y al docente de C2', function (Role $role, array $scope) {
    $user = User::factory()->withRole($role, ['campus_id' => $this->c2->id, ...$scope])->create();

    expect($user->can('viewAny', Department::class))->toBeFalse()
        ->and($user->can('create', Department::class))->toBeFalse()
        ->and($user->can('view', $this->department))->toBeFalse()
        ->and($user->can('update', $this->department))->toBeFalse()
        ->and($user->can('delete', $this->department))->toBeFalse();
})->with([
    'subdirección' => [Role::Subdireccion, []],
    'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
    'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
    'docente' => [Role::Docente, []],
]);

it('niega todo a un admin desactivado', function () {
    $admin = User::factory()->admin()->create(['is_active' => false]);

    expect($admin->can('viewAny', Department::class))->toBeFalse()
        ->and($admin->can('create', Department::class))->toBeFalse()
        ->and($admin->can('update', $this->department))->toBeFalse()
        ->and($admin->can('delete', $this->department))->toBeFalse();
});
