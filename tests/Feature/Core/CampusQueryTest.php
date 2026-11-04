<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\CampusQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/*
| CampusQuery: lecturas del CRUD de Campus. forTable() es la tabla, por código, paginada, con búsqueda
| literal por código o nombre (TextSearch) y filtro por estado; lista lo que la policy deja ver: al
| admin, todos; a la subdirección, sus campus activos de Actor::campuses(); a nadie más,
| ninguno. findOrFail() carga el campus que se va a editar.
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
        ->forTable(app(Actor::class), $search, $active, $perPage)
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

it('toma literalmente %, _ y \\ en la búsqueda', function () {
    Campus::factory()->create(['code' => 'C_9', 'name' => 'Campus nueve']);
    Campus::factory()->create(['code' => 'CX9', 'name' => 'Campus al 100%']);
    Campus::factory()->create(['code' => 'CB9', 'name' => 'Campus con \\ barra']);
    $admin = User::factory()->admin()->create();

    expect(campusCodesFor($admin, '_'))->toBe(['C_9'])
        ->and(campusCodesFor($admin, '%'))->toBe(['CX9'])
        ->and(campusCodesFor($admin, '\\'))->toBe(['CB9']);
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

    $page = app(CampusQuery::class)->forTable(app(Actor::class), perPage: 2);

    expect($page->total())->toBe(3)
        ->and($page->pluck('code')->all())->toBe(['C1', 'C2']);
});

it('a la subdirección le lista solo los campus de su alcance', function () {
    $subdireccion = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->c1->id])->create();

    expect(campusCodesFor($subdireccion))->toBe(['C1']);
});

it('a la subdirección nunca le lista inactivos, aunque su alcance sea de uno o los pida', function () {
    // El alcance en EAD es de otro puesto: la Subdirección es una sola por unidad en cada fecha (AZ-S7).
    $subdireccion = User::factory()
        ->withRole(Role::Subdireccion, ['campus_id' => $this->c1->id])
        ->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->ead->id, 'department_id' => departmentKey(10)])
        ->create();

    expect(campusCodesFor($subdireccion))->toBe(['C1'])
        ->and(campusCodesFor($subdireccion, active: false))->toBe([]);
});

it('a quien no administra campus no le lista ninguno, aunque trabaje en uno', function (Role $role, array $scope) {
    $user = User::factory()->withRole($role, ['campus_id' => $this->c1->id, ...$scope])->create();

    expect(campusCodesFor($user))->toBe([]);
})->with([
    'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
    'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
    'docente' => [Role::Docente, []],
]);

it('carga el campus que se va a editar, y no uno borrado', function () {
    expect(app(CampusQuery::class)->findOrFail($this->c2->id)->is($this->c2))->toBeTrue();

    $this->c2->delete();

    expect(fn () => app(CampusQuery::class)->findOrFail($this->c2->id))->toThrow(ModelNotFoundException::class);
});

// ADR-0014: el id llega del cliente; uno que no es uuid no debe llegar a PostgreSQL, que lo rechaza con un error.
it('no encuentra un campus con un id que no es uuid', function (string $id) {
    expect(fn () => app(CampusQuery::class)->findOrFail($id))->toThrow(ModelNotFoundException::class);
})->with(['número' => '999999', 'texto' => 'no-es-un-uuid', 'vacío' => '']);

it('activeOptions da solo los campus activos, por código', function () {
    Campus::factory()->create(['code' => 'X2', 'name' => 'Campus II']);
    Campus::factory()->create(['code' => 'X1', 'name' => 'Campus I']);
    Campus::factory()->create(['code' => 'X3', 'name' => 'Campus III', 'is_active' => false]);

    expect(collect((new CampusQuery)->activeOptions())->pluck('name')->filter(fn (string $name): bool => str_starts_with($name, 'X'))->values()->all())->toBe(['X1 · Campus I', 'X2 · Campus II']);
});

it('active da los campus activos por código, sin los inactivos', function () {
    Campus::factory()->create(['code' => 'Y2']);
    Campus::factory()->create(['code' => 'Y1']);
    Campus::factory()->create(['code' => 'Y3', 'is_active' => false]);

    expect((new CampusQuery)->active()->pluck('code')->filter(fn (string $code): bool => str_starts_with($code, 'Y'))->values()->all())->toBe(['Y1', 'Y2']);
});
