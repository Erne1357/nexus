<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\CampusPolicy;

/*
| CampusPolicy. Según reglas-de-negocio.md, "Aprobaciones y permisos" (fila "Abrir periodo,
| calendario, perfil de campus": `admin`, `subdireccion`):
| - el admin da de alta campus y edita sus datos, su estado y su perfil de flujo, en cualquier campus;
| - la subdirección ve los campus de su alcance y edita solo su perfil de flujo (`settings`);
| - nadie más administra campus, y nadie los borra (catálogo: se desactivan).
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
});

it('al admin le permite ver, dar de alta y editar datos, estado y perfil de cualquier campus, también inactivo', function () {
    $admin = User::factory()->admin()->create();
    $inactive = Campus::factory()->inactive()->create();

    expect($admin->can('viewAny', Campus::class))->toBeTrue()
        ->and($admin->can('create', Campus::class))->toBeTrue();

    foreach ([$this->c1, $this->c2, $inactive] as $campus) {
        expect($admin->can('view', $campus))->toBeTrue()
            ->and($admin->can('update', $campus))->toBeTrue()
            ->and($admin->can('updateProfile', $campus))->toBeTrue();
    }
});

it('a la subdirección le permite ver y editar el perfil de flujo de los campus de su alcance (reglas-de-negocio.md, "perfil de campus")', function () {
    $subdireccion = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->c1->id])->create();

    expect($subdireccion->can('viewAny', Campus::class))->toBeTrue()
        ->and($subdireccion->can('view', $this->c1))->toBeTrue()
        ->and($subdireccion->can('updateProfile', $this->c1))->toBeTrue();
});

it('a la subdirección no le permite dar de alta campus ni cambiar datos o estado, ni del suyo', function () {
    $subdireccion = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->c1->id])->create();

    expect($subdireccion->can('create', Campus::class))->toBeFalse()
        ->and($subdireccion->can('update', $this->c1))->toBeFalse();
});

it('a la subdirección de C1 no le permite ver ni editar C2', function () {
    $subdireccion = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->c1->id])->create();

    expect($subdireccion->can('view', $this->c2))->toBeFalse()
        ->and($subdireccion->can('updateProfile', $this->c2))->toBeFalse()
        ->and($subdireccion->can('update', $this->c2))->toBeFalse();
});

it('niega todo a los demás roles de R1, aun en su propio campus', function (Role $role, array $scope) {
    $user = User::factory()->withRole($role, ['campus_id' => $this->c1->id, ...$scope])->create();

    expect($user->can('viewAny', Campus::class))->toBeFalse()
        ->and($user->can('create', Campus::class))->toBeFalse()
        ->and($user->can('view', $this->c1))->toBeFalse()
        ->and($user->can('update', $this->c1))->toBeFalse()
        ->and($user->can('updateProfile', $this->c1))->toBeFalse();
})->with([
    'coordinador' => [Role::Coordinador, ['career_id' => 20]],
    'jefe de departamento' => [Role::JefeDepartamento, ['department_id' => 10]],
    'jefe de proyecto docente' => [Role::JefeProyectoDocente, ['department_id' => 10]],
    'docente' => [Role::Docente, []],
]);

it('niega todo a un admin desactivado', function () {
    $admin = User::factory()->admin()->create(['is_active' => false]);

    expect($admin->can('viewAny', Campus::class))->toBeFalse()
        ->and($admin->can('update', $this->c1))->toBeFalse();
});

it('no deja borrar campus a nadie, ni al admin: el catálogo se desactiva', function () {
    $admin = User::factory()->admin()->create();

    expect(method_exists(CampusPolicy::class, 'delete'))->toBeFalse()
        ->and($admin->can('delete', $this->c1))->toBeFalse()
        ->and($admin->can('forceDelete', $this->c1))->toBeFalse();
});
