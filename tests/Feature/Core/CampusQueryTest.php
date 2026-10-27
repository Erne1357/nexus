<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\CampusQuery;
use App\Modules\Core\Queries\ScopedContext;

/*
| CampusQuery::forTable(): la tabla del CRUD de Campus, por código, paginada, con búsqueda por código
| o nombre y filtro por estado. Lista lo que la policy deja ver: al admin, todos; a la subdirección,
| los campus de su alcance; a nadie más, ninguno.
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->ead = Campus::factory()->inactive()->create(['code' => 'EAD', 'name' => 'Educación a Distancia']);
});

/**
 * @return list<string>
 */
function campusCodesFor(User $user, string $search = '', ?bool $active = null, int $perPage = 15): array
{
    test()->actingAs($user);

    return app(CampusQuery::class)
        ->forTable(app(ScopedContext::class), $search, $active, $perPage)
        ->pluck('code')
        ->all();
}

it('al admin le lista todos los campus, activos e inactivos, por código', function () {
    expect(campusCodesFor(User::factory()->admin()->create()))->toBe(['C1', 'C2', 'EAD']);
});

it('busca por código o por nombre sin distinguir mayúsculas', function () {
    $admin = User::factory()->admin()->create();

    expect(campusCodesFor($admin, 'c2'))->toBe(['C2'])
        ->and(campusCodesFor($admin, 'distancia'))->toBe(['EAD'])
        ->and(campusCodesFor($admin, '  campus i  '))->toBe(['C1', 'C2']);
});

it('filtra los activos o los inactivos', function () {
    $admin = User::factory()->admin()->create();

    expect(campusCodesFor($admin, active: true))->toBe(['C1', 'C2'])
        ->and(campusCodesFor($admin, active: false))->toBe(['EAD']);
});

it('no lista los campus borrados', function () {
    $this->c2->delete();

    expect(campusCodesFor(User::factory()->admin()->create()))->toBe(['C1', 'EAD']);
});

it('pagina el listado', function () {
    $this->actingAs(User::factory()->admin()->create());

    $page = app(CampusQuery::class)->forTable(app(ScopedContext::class), perPage: 2);

    expect($page->total())->toBe(3)
        ->and($page->pluck('code')->all())->toBe(['C1', 'C2']);
});

it('a la subdirección le lista solo los campus de su alcance', function () {
    $subdireccion = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->c1->id])->create();

    expect(campusCodesFor($subdireccion))->toBe(['C1']);
});

it('a quien no administra campus no le lista ninguno, aunque trabaje en uno', function (Role $role, array $scope) {
    $user = User::factory()->withRole($role, ['campus_id' => $this->c1->id, ...$scope])->create();

    expect(campusCodesFor($user))->toBe([]);
})->with([
    'coordinador' => [Role::Coordinador, ['career_id' => 20]],
    'jefe de departamento' => [Role::JefeDepartamento, ['department_id' => 10]],
    'docente' => [Role::Docente, []],
]);
