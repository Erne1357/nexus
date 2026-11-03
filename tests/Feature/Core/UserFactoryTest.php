<?php

use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;

it('withRole() rechaza un alcance que no llena lo que exige el rol', function (Role $role, array $scope) {
    expect(fn () => User::factory()->withRole($role, $scope))->toThrow(InvalidArgumentException::class);
})->with([
    'coordinador sin carrera' => [Role::Coordinador, ['campus_id' => fakeKey(1)]],
    'coordinador sin campus' => [Role::Coordinador, ['career_id' => fakeKey(20)]],
    'jefe de departamento sin departamento' => [Role::JefeDepartamento, ['campus_id' => fakeKey(1)]],
    'jefe de proyecto docente sin campus' => [Role::JefeProyectoDocente, ['department_id' => fakeKey(10)]],
    'jefe de proyecto docente con campus nulo' => [Role::JefeProyectoDocente, ['campus_id' => null, 'department_id' => fakeKey(10)]],
]);

it('withRole() da el rol y el alcance cuando el alcance está completo', function () {
    $campus = Campus::factory()->create();

    $user = User::factory()->withRole(Role::Coordinador, ['campus_id' => $campus->id, 'career_id' => careerKey(20)])->create();

    expect($user->hasRole(Role::Coordinador))->toBeTrue()
        ->and($user->roleAssignments()->sole())
        ->campus_id->toBe($campus->id)
        ->career_id->toBe(fakeKey(20));
});

it('withRole() da a los roles de todo el Tec un alcance sin campus, sin crear ninguno', function (Role $role, array $scope, ?Closure $unit) {
    $scope = array_map(fn ($value) => $value instanceof Closure ? $value() : $value, $scope);

    $user = User::factory()->withRole($role, $scope)->create();

    expect($user->hasRole($role))->toBeTrue()
        ->and($user->roleAssignments()->sole())
        ->campus_id->toBeNull()
        ->org_unit_id->toBe($unit?->__invoke())
        ->and(Campus::count())->toBe(0);
})->with([
    'subdirección, sobre Subdirección Académica' => [Role::Subdireccion, [], fn () => app(OrgTree::class)->idOf(OrgTree::ACADEMIC)],
    'docente' => [Role::Docente, [], null],
    'jefe de departamento, con department_id como otro nombre de org_unit_id' => [Role::JefeDepartamento, ['department_id' => fn () => departmentKey(10)], fn () => fakeKey(10)],
    'jefe de departamento con org_unit_id' => [Role::JefeDepartamento, ['org_unit_id' => fn () => departmentKey(10)], fn () => fakeKey(10)],
]);

it('admin() da el rol de spatie y un nombramiento admin sin alcance', function () {
    $user = User::factory()->admin()->create();

    expect($user->hasRole(Role::Admin))->toBeTrue()
        ->and($user->roleAssignments()->sole())
        ->role->toBe(Role::Admin)
        ->campus_id->toBeNull()
        ->org_unit_id->toBeNull()
        ->career_id->toBeNull()
        ->valid_to->toBeNull();
});
