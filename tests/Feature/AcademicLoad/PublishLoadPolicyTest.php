<?php

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;

/*
| Publicar (reglas-de-negocio.md, tabla de permisos): Subdirección, o el jefe del departamento si el campus no exige
| visto bueno. El admin, siempre.
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->load = AcademicLoad::factory()->create(['campus_id' => $this->campus->id, 'department_id' => departmentKey(10), 'status' => LoadStatus::Closed]);
});

it('en un campus sin visto bueno publican Subdirección y el jefe del departamento', function (Role $role, array $scope, bool $publish) {
    $user = User::factory()->withRole($role, ['campus_id' => $this->campus->id, ...$scope])->create();

    expect($user->can('publish', $this->load))->toBe($publish);
})->with([
    'subdirección' => [Role::Subdireccion, [], true],
    'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)], true],
    'jefe de otro departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(11)], false],
    'jefe de proyecto docente' => [Role::JefeProyectoDocente, fn () => ['department_id' => departmentKey(10)], false],
    'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)], false],
    'docente' => [Role::Docente, [], false],
]);

it('en un campus con visto bueno solo publica Subdirección', function (Role $role, array $scope, bool $publish) {
    $this->campus->update(['settings' => ['requires_subdireccion_approval' => true]]);
    $user = User::factory()->withRole($role, ['campus_id' => $this->campus->id, ...$scope])->create();

    expect($user->can('publish', $this->load->fresh()))->toBe($publish);
})->with([
    'subdirección' => [Role::Subdireccion, [], true],
    'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)], false],
]);

it('el admin publica cualquier carga y uno desactivado ninguna', function () {
    expect(User::factory()->admin()->create()->can('publish', $this->load))->toBeTrue()
        ->and(User::factory()->admin()->create(['is_active' => false])->can('publish', $this->load))->toBeFalse();
});
