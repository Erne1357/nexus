<?php

use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;

/*
| SubjectPolicy. Según reglas-de-negocio.md, "Aprobaciones y permisos" (fila "Administrar catálogos
| de Academic": `admin`), solo el admin ve, da de alta, edita, activa y desactiva materias; en la
| fase 2 se abrirán a los jefes para validar.
*/

beforeEach(function () {
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->subject = Subject::factory()->create();
});

it('al admin le permite todo, también sobre una materia inactiva', function () {
    $admin = User::factory()->admin()->create();
    $inactive = Subject::factory()->inactive()->create();

    expect($admin->can('viewAny', Subject::class))->toBeTrue()
        ->and($admin->can('create', Subject::class))->toBeTrue();

    foreach ([$this->subject, $inactive] as $subject) {
        expect($admin->can('view', $subject))->toBeTrue()
            ->and($admin->can('update', $subject))->toBeTrue();
    }
});

it('niega todo a la subdirección, al jefe, al coordinador y al docente de C2', function (Role $role, array $scope) {
    $user = User::factory()->withRole($role, ['campus_id' => $this->c2->id, ...$scope])->create();

    expect($user->can('viewAny', Subject::class))->toBeFalse()
        ->and($user->can('create', Subject::class))->toBeFalse()
        ->and($user->can('view', $this->subject))->toBeFalse()
        ->and($user->can('update', $this->subject))->toBeFalse();
})->with([
    'subdirección' => [Role::Subdireccion, []],
    'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
    'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
    'docente' => [Role::Docente, []],
]);

it('niega todo a un admin desactivado', function () {
    $admin = User::factory()->admin()->create(['is_active' => false]);

    expect($admin->can('viewAny', Subject::class))->toBeFalse()
        ->and($admin->can('create', Subject::class))->toBeFalse()
        ->and($admin->can('view', $this->subject))->toBeFalse()
        ->and($admin->can('update', $this->subject))->toBeFalse();
});

it('no hay ability para borrar: las materias no se borran, se desactivan', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->can('delete', $this->subject))->toBeFalse();
});
