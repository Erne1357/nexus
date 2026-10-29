<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Policies\ImportBatchPolicy;

/*
| ImportBatchPolicy. Según reglas-de-negocio.md, "Aprobaciones y permisos" (fila "Importar
| catálogos": `admin`): solo el admin ve, sube y aplica lotes de importación, en cualquier campus.
| Un lote no se borra: es el registro de qué se importó.
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create();
    $this->batch = ImportBatch::factory()->create(['campus_id' => $this->campus->id]);
});

it('al admin le permite ver, subir y aplicar lotes de cualquier campus', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->can('viewAny', ImportBatch::class))->toBeTrue()
        ->and($admin->can('create', ImportBatch::class))->toBeTrue()
        ->and($admin->can('view', $this->batch))->toBeTrue()
        ->and($admin->can('apply', $this->batch))->toBeTrue();
});

it('niega todo a los demás roles, aun en el campus del lote', function (Role $role, array $scope) {
    $user = User::factory()->withRole($role, ['campus_id' => $this->campus->id, ...$scope])->create();

    expect($user->can('viewAny', ImportBatch::class))->toBeFalse()
        ->and($user->can('create', ImportBatch::class))->toBeFalse()
        ->and($user->can('view', $this->batch))->toBeFalse()
        ->and($user->can('apply', $this->batch))->toBeFalse();
})->with([
    'subdirección' => [Role::Subdireccion, []],
    'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
    'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
    'jefe de proyecto docente' => [Role::JefeProyectoDocente, fn () => ['department_id' => departmentKey(10)]],
    'docente' => [Role::Docente, []],
]);

it('niega todo a un admin desactivado', function () {
    $admin = User::factory()->admin()->create(['is_active' => false]);

    expect($admin->can('viewAny', ImportBatch::class))->toBeFalse()
        ->and($admin->can('apply', $this->batch))->toBeFalse();
});

it('no deja borrar lotes a nadie, ni al admin', function () {
    $admin = User::factory()->admin()->create();

    expect(method_exists(ImportBatchPolicy::class, 'delete'))->toBeFalse()
        ->and($admin->can('delete', $this->batch))->toBeFalse();
});
