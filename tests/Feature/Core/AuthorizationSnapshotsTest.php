<?php

use App\Modules\Core\Authorization\AssignmentScope;
use App\Modules\Core\Authorization\AuthorizationSnapshot;
use App\Modules\Core\Authorization\AuthorizationSnapshots;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\ScopedContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| Snapshot cacheado de los nombramientos (spec 9): llave `authz:user:{id}:{authz_version}`, vence a la siguiente
| medianoche de Ciudad Juárez. Cada «petición» lee al usuario de la base, como el guard de sesión, para que su
| authz_version sea la vigente; un usuario recién creado por la factory no la trae y no usa la caché.
| "Hoy" es el 3 de noviembre de 2026.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00', 'America/Ciudad_Juarez'));
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    Cache::flush();
    app()->forgetScopedInstances();
});

/**
 * Una petición nueva del usuario, leído de la base como lo lee el guard de sesión.
 */
function nextRequestOf(User $user): ScopedContext
{
    app()->forgetScopedInstances();
    test()->actingAs(User::find($user->id));

    return app(ScopedContext::class);
}

/**
 * @return list<string>
 */
function roleAssignmentQueries(): array
{
    return collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'role_assignments'))->values()->all();
}

it('guarda los nombramientos vigentes y completos, por puesto, y vuelve igual de la caché', function () {
    $user = User::factory()
        ->admin()
        ->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])
        ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])
        ->create();
    RoleAssignment::factory()->for($user)->create(['role' => Role::JefeProyectoDocente, 'campus_id' => null, 'org_unit_id' => departmentKey(11)]);
    RoleAssignment::factory()->for($user)->create(['role' => Role::Coordinador, 'campus_id' => $this->c2->id, 'career_id' => careerKey(21), 'valid_to' => today()->subDay()->toDateString()]);

    $snapshot = app(AuthorizationSnapshots::class)->for(User::find($user->id));

    expect($snapshot->roles())->toBe([Role::Admin, Role::JefeDepartamento, Role::Coordinador])
        ->and($snapshot->scopes(Role::JefeDepartamento))->toEqual([new AssignmentScope(Role::JefeDepartamento, null, fakeKey(10), null, true)])
        ->and($snapshot->scopes(Role::Coordinador))->toEqual([new AssignmentScope(Role::Coordinador, $this->c2->id, null, fakeKey(20), false)])
        ->and($snapshot->scopes())->toHaveCount(3)
        ->and(AuthorizationSnapshot::fromArray($snapshot->toArray()))->toEqual($snapshot);
});

it('el snapshot se lee de la caché en la segunda petición', function () {
    $user = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create();

    expect(nextRequestOf($user)->has(Role::Coordinador))->toBeTrue()
        ->and(Cache::has("authz:user:{$user->id}:".User::find($user->id)->authz_version))->toBeTrue();

    DB::enableQueryLog();

    expect(nextRequestOf($user)->has(Role::Coordinador))->toBeTrue()
        ->and(roleAssignmentQueries())->toBe([]);
});

it('un nombramiento nuevo cuenta en la siguiente petición', function () {
    $user = User::factory()->withRole(Role::Docente)->create();
    $before = User::find($user->id);

    expect(nextRequestOf($user)->has(Role::Coordinador))->toBeFalse();

    RoleAssignment::factory()->for($user)->create(['role' => Role::Coordinador, 'campus_id' => $this->c2->id, 'career_id' => careerKey(20)]);

    expect(nextRequestOf($user)->has(Role::Coordinador))->toBeTrue()
        ->and(app(AuthorizationSnapshots::class)->for($before)->roles())->toBe([Role::Docente]);
});

it('un nombramiento que empieza mañana cuenta desde mañana', function () {
    $user = User::factory()->withRole(Role::Docente)->create();
    RoleAssignment::factory()->for($user)->create([
        'role' => Role::Coordinador, 'campus_id' => $this->c2->id, 'career_id' => careerKey(20), 'valid_from' => today()->addDay()->toDateString(),
    ]);

    expect(nextRequestOf($user)->has(Role::Coordinador))->toBeFalse();

    $this->travelTo(CarbonImmutable::parse('2026-11-03 23:59', 'America/Ciudad_Juarez'));

    expect(nextRequestOf($user)->has(Role::Coordinador))->toBeFalse();

    $this->travelTo(CarbonImmutable::parse('2026-11-04 00:01', 'America/Ciudad_Juarez'));

    expect(nextRequestOf($user)->has(Role::Coordinador))->toBeTrue();
});

it('sin authz_version cargada lee los nombramientos de la base y no los guarda en caché', function () {
    $user = User::factory()->withRole(Role::Docente)->create();

    expect(app(AuthorizationSnapshots::class)->for($user)->roles())->toBe([Role::Docente])
        ->and(Cache::has("authz:user:{$user->id}:".User::find($user->id)->authz_version))->toBeFalse();

    RoleAssignment::factory()->for($user)->create(['role' => Role::Coordinador, 'campus_id' => $this->c2->id, 'career_id' => careerKey(20)]);

    expect(app(AuthorizationSnapshots::class)->for($user)->roles())->toBe([Role::Coordinador, Role::Docente]);
});

it('refresh() vuelve a leer authz_version y ve un nombramiento nuevo en la misma petición', function () {
    $user = User::factory()->withRole(Role::Docente)->create();
    $context = nextRequestOf($user);

    expect($context->has(Role::Coordinador))->toBeFalse();

    RoleAssignment::factory()->for($user)->create(['role' => Role::Coordinador, 'campus_id' => $this->c2->id, 'career_id' => careerKey(20)]);

    expect($context->has(Role::Coordinador))->toBeFalse();

    $context->refresh();

    expect($context->has(Role::Coordinador))->toBeTrue()
        ->and($context->user()->authz_version)->toBe(User::find($user->id)->authz_version)
        ->and($context->user()->isDirty())->toBeFalse();
});
