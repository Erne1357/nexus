<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\User;

/*
| ModalityPolicy, como la de campus: el admin da de alta y edita datos, estado y perfil de flujo de toda modalidad; la
| subdirección ve las modalidades de su alcance y edita solo el perfil de flujo; nadie más las administra y nadie las
| borra (catálogo: se desactivan).
*/

beforeEach(function () {
    $this->pres = modalityOf('PRES');
    $this->ead = modalityOf('EAD');
    $this->campus = Campus::factory()->create(['code' => 'C1']);
});

it('al admin le permite ver, dar de alta y editar datos, estado y perfil, también de una inactiva', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->can('viewAny', Modality::class))->toBeTrue()
        ->and($admin->can('create', Modality::class))->toBeTrue();

    foreach ([$this->pres, $this->ead] as $modality) {
        expect($admin->can('view', $modality))->toBeTrue()
            ->and($admin->can('update', $modality))->toBeTrue()
            ->and($admin->can('updateProfile', $modality))->toBeTrue();
    }
});

it('a la subdirección le permite ver y editar el perfil, no dar de alta ni cambiar datos o estado', function () {
    $subdireccion = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->campus->id])->create();

    expect($subdireccion->can('viewAny', Modality::class))->toBeTrue()
        ->and($subdireccion->can('view', $this->pres))->toBeTrue()
        ->and($subdireccion->can('updateProfile', $this->pres))->toBeTrue()
        ->and($subdireccion->can('create', Modality::class))->toBeFalse()
        ->and($subdireccion->can('update', $this->pres))->toBeFalse();
});

it('a una subdirección acotada a otra modalidad no le permite ver ni editar esta', function () {
    $subdireccion = User::factory()->withRole(Role::Subdireccion, ['modality_id' => $this->pres->id])->create();

    expect($subdireccion->can('view', $this->pres))->toBeTrue()
        ->and($subdireccion->can('view', $this->ead))->toBeFalse()
        ->and($subdireccion->can('updateProfile', $this->ead))->toBeFalse();
});

it('niega todo a los demás roles', function (Role $role, array $scope) {
    $user = User::factory()->withRole($role, ['campus_id' => $this->campus->id, ...$scope])->create();

    expect($user->can('viewAny', Modality::class))->toBeFalse()
        ->and($user->can('view', $this->pres))->toBeFalse()
        ->and($user->can('create', Modality::class))->toBeFalse()
        ->and($user->can('update', $this->pres))->toBeFalse()
        ->and($user->can('updateProfile', $this->pres))->toBeFalse();
})->with([
    'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
    'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
    'docente' => [Role::Docente, []],
]);
