<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Fixtures\Modules\DemoCatalog\Models\Item;

/*
| ItemPolicy (tests/Fixtures/Modules/DemoCatalog) es la policy de ejemplo de UsesScopedContext:
| ve el elemento quien trabaja en su campus; lo edita el jefe de su departamento o el coordinador
| de su carrera en ese campus. El elemento es del departamento 10 y la carrera 20 en C2.
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);

    $this->item = new Item(['name' => 'Elemento', 'campus_id' => $this->c2->id, 'department_id' => departmentKey(10), 'career_id' => careerKey(20)]);
});

it('permite editar al jefe del departamento del elemento en su campus', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->c2->id, 'department_id' => departmentKey(10)])->create();

    expect($jefe->can('update', $this->item))->toBeTrue();
});

it('permite editar al coordinador de la carrera del elemento en su campus', function () {
    $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create();

    expect($coordinador->can('update', $this->item))->toBeTrue();
});

it('permite ver y editar al admin sin alcance', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->can('view', $this->item))->toBeTrue()
        ->and($admin->can('update', $this->item))->toBeTrue();
});

it('niega editar a otro rol del mismo campus, aunque pueda verlo', function (Role $role, array $scope) {
    $user = User::factory()->withRole($role, ['campus_id' => $this->c2->id, ...$scope])->create();

    expect($user->can('view', $this->item))->toBeTrue()
        ->and($user->can('update', $this->item))->toBeFalse();
})->with([
    'docente' => [Role::Docente, []],
    'subdirección' => [Role::Subdireccion, []],
    'jefe de proyecto docente del departamento' => [Role::JefeProyectoDocente, fn () => ['department_id' => departmentKey(10)]],
]);

it('niega ver y editar al jefe del mismo departamento en otro campus', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->c1->id, 'department_id' => departmentKey(10)])->create();

    expect($jefe->can('view', $this->item))->toBeFalse()
        ->and($jefe->can('update', $this->item))->toBeFalse();
});

it('niega editar al jefe de otro departamento y al coordinador de otra carrera del mismo campus', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->c2->id, 'department_id' => departmentKey(11)])->create();
    $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(21)])->create();

    expect($jefe->can('update', $this->item))->toBeFalse()
        ->and($coordinador->can('update', $this->item))->toBeFalse();
});

it('niega todo a quien tiene el rol pero ningún alcance vigente', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, [
        'campus_id' => $this->c2->id, 'department_id' => departmentKey(10), 'valid_from' => '2025-01-01', 'valid_to' => '2025-12-31',
    ])->create();

    expect($jefe->can('view', $this->item))->toBeFalse()
        ->and($jefe->can('update', $this->item))->toBeFalse();
});

it('decide por el usuario que recibe la policy, no por el autenticado', function () {
    $admin = User::factory()->admin()->create();
    $docente = User::factory()->withRole(Role::Docente, ['campus_id' => $this->c2->id])->create();

    $this->actingAs($admin);
    expect(Gate::forUser($docente)->allows('update', $this->item))->toBeFalse();

    $this->actingAs($docente);
    expect(Gate::forUser($admin)->allows('update', $this->item))->toBeTrue()
        ->and(Gate::allows('update', $this->item))->toBeFalse();
});

it('pregunta por otro usuario sin cargar los roles ni alcances del autenticado', function () {
    $this->actingAs(User::factory()->admin()->create());
    $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create();

    DB::enableQueryLog();
    Gate::forUser($coordinador)->allows('update', $this->item);

    $scopeQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'role_scopes'));

    expect($scopeQueries)->toHaveCount(1);
});

it('recuerda el contexto de otro usuario: repetir la policy no repite consultas', function () {
    $this->actingAs(User::factory()->admin()->create());
    $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create();
    Gate::forUser($coordinador)->allows('update', $this->item);

    DB::enableQueryLog();

    foreach (range(1, 5) as $call) {
        Gate::forUser($coordinador)->allows('update', $this->item);
    }

    expect(DB::getQueryLog())->toBeEmpty();
});
