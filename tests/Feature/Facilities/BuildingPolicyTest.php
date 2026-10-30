<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Policies\BuildingPolicy;

/*
| BuildingPolicy. Según la spec del carril C (confirmado el 27 de octubre de 2026), solo el admin y la
| Subdirección de ese campus administran edificios. Sin `delete`: se desactiva.
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->building = Building::factory()->create(['campus_id' => $this->c2->id]);
});

it('al admin le permite todo, también en un edificio inactivo', function () {
    $admin = User::factory()->admin()->create();
    $inactive = Building::factory()->inactive()->create(['campus_id' => $this->c1->id]);

    expect($admin->can('viewAny', Building::class))->toBeTrue()
        ->and($admin->can('create', [Building::class, $this->c1]))->toBeTrue();

    foreach ([$this->building, $inactive] as $building) {
        expect($admin->can('view', $building))->toBeTrue()
            ->and($admin->can('update', $building))->toBeTrue();
    }
});

it('a la subdirección de C2 le permite solo C2', function () {
    $user = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->c2->id])->create();
    $other = Building::factory()->create(['campus_id' => $this->c1->id]);

    expect($user->can('viewAny', Building::class))->toBeTrue()
        ->and($user->can('create', [Building::class, $this->c2]))->toBeTrue()
        ->and($user->can('view', $this->building))->toBeTrue()
        ->and($user->can('update', $this->building))->toBeTrue()
        ->and($user->can('create', [Building::class, $this->c1]))->toBeFalse()
        ->and($user->can('view', $other))->toBeFalse()
        ->and($user->can('update', $other))->toBeFalse();
});

it('niega todo al jefe, al coordinador y al docente', function (Role $role, array $scope) {
    $user = User::factory()->withRole($role, ['campus_id' => $this->c2->id, ...$scope])->create();

    expect($user->can('viewAny', Building::class))->toBeFalse()
        ->and($user->can('create', [Building::class, $this->c2]))->toBeFalse()
        ->and($user->can('view', $this->building))->toBeFalse()
        ->and($user->can('update', $this->building))->toBeFalse();
})->with([
    'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
    'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
    'docente' => [Role::Docente, []],
]);

it('niega todo a un admin y a una subdirección desactivados', function () {
    $admin = User::factory()->admin()->create(['is_active' => false]);
    $subdireccion = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->c2->id])->create(['is_active' => false]);

    foreach ([$admin, $subdireccion] as $user) {
        expect($user->can('viewAny', Building::class))->toBeFalse()
            ->and($user->can('update', $this->building))->toBeFalse();
    }
});

it('no define delete: un edificio se desactiva', function () {
    expect(method_exists(BuildingPolicy::class, 'delete'))->toBeFalse()
        ->and(method_exists(BuildingPolicy::class, 'forceDelete'))->toBeFalse();
});
