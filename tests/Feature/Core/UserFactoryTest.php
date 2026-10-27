<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;

it('withRole() rechaza un alcance que no llena lo que exige el rol', function (Role $role, array $scope) {
    expect(fn () => User::factory()->withRole($role, $scope))->toThrow(InvalidArgumentException::class);
})->with([
    'coordinador sin carrera' => [Role::Coordinador, ['campus_id' => fakeKey(1)]],
    'jefe de departamento sin departamento' => [Role::JefeDepartamento, ['campus_id' => fakeKey(1)]],
    'docente sin campus' => [Role::Docente, []],
    'subdirección con campus nulo' => [Role::Subdireccion, ['campus_id' => null]],
]);

it('withRole() da el rol y el alcance cuando el alcance está completo', function () {
    $campus = Campus::factory()->create();

    $user = User::factory()->withRole(Role::Coordinador, ['campus_id' => $campus->id, 'career_id' => fakeKey(20)])->create();

    expect($user->hasRole(Role::Coordinador))->toBeTrue()
        ->and($user->roleScopes()->sole())
        ->campus_id->toBe($campus->id)
        ->career_id->toBe(fakeKey(20));
});
