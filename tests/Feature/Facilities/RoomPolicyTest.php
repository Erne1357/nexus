<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Policies\RoomPolicy;

/*
| RoomPolicy. Según la spec del carril C (confirmado el 27 de octubre de 2026), solo el admin y la
| Subdirección de ese campus administran salones. Sin `delete`: se desactiva.
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->room = Room::factory()->for(Building::factory()->for($this->c2))->create();
});

it('al admin le permite todo, también en un salón inactivo', function () {
    $admin = User::factory()->admin()->create();
    $inactive = Room::factory()->inactive()->for(Building::factory()->for($this->c1))->create();

    expect($admin->can('viewAny', Room::class))->toBeTrue()
        ->and($admin->can('create', [Room::class, $this->c1]))->toBeTrue();

    foreach ([$this->room, $inactive] as $room) {
        expect($admin->can('view', $room))->toBeTrue()
            ->and($admin->can('update', $room))->toBeTrue();
    }
});

it('a la subdirección de C2 le permite solo C2', function () {
    $user = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->c2->id])->create();
    $other = Room::factory()->for(Building::factory()->for($this->c1))->create();

    expect($user->can('viewAny', Room::class))->toBeTrue()
        ->and($user->can('create', [Room::class, $this->c2]))->toBeTrue()
        ->and($user->can('view', $this->room))->toBeTrue()
        ->and($user->can('update', $this->room))->toBeTrue()
        ->and($user->can('create', [Room::class, $this->c1]))->toBeFalse()
        ->and($user->can('view', $other))->toBeFalse()
        ->and($user->can('update', $other))->toBeFalse();
});

it('niega todo al jefe, al coordinador y al docente', function (Role $role, array $scope) {
    $user = User::factory()->withRole($role, ['campus_id' => $this->c2->id, ...$scope])->create();

    expect($user->can('viewAny', Room::class))->toBeFalse()
        ->and($user->can('create', [Room::class, $this->c2]))->toBeFalse()
        ->and($user->can('view', $this->room))->toBeFalse()
        ->and($user->can('update', $this->room))->toBeFalse();
})->with([
    'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
    'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
    'docente' => [Role::Docente, []],
]);

it('niega todo a un admin y a una subdirección desactivados', function () {
    $admin = User::factory()->admin()->create(['is_active' => false]);
    $subdireccion = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->c2->id])->create(['is_active' => false]);

    foreach ([$admin, $subdireccion] as $user) {
        expect($user->can('viewAny', Room::class))->toBeFalse()
            ->and($user->can('update', $this->room))->toBeFalse();
    }
});

it('no define delete: un salón se desactiva', function () {
    expect(method_exists(RoomPolicy::class, 'delete'))->toBeFalse()
        ->and(method_exists(RoomPolicy::class, 'forceDelete'))->toBeFalse();
});
