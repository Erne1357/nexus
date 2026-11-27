<?php

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\AssignmentScope;
use App\Modules\Core\Authorization\AuthorizationSnapshot;
use App\Modules\Core\Authorization\AuthorizationSnapshots;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| Snapshot cacheado de los nombramientos (spec 9): llave `authz:user:v{FORMAT}:{id}:{authz_version}` (FORMAT es la
| versión del formato, AuthorizationSnapshots::FORMAT), vence a la siguiente medianoche de Ciudad Juárez. Cada
| «petición» lee al usuario de la base, como el guard de sesión, para que su authz_version sea la vigente; un usuario recién creado por la factory no la trae y no usa la caché.
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
function nextRequestOf(User $user): Actor
{
    app()->forgetScopedInstances();
    test()->actingAs(User::find($user->id));

    return app(Actor::class);
}

/**
 * La llave de caché del snapshot del usuario, con la versión del formato y su authz_version de la base.
 */
function snapshotKeyOf(User $user): string
{
    return 'authz:user:v'.AuthorizationSnapshots::FORMAT.":{$user->id}:".User::find($user->id)->authz_version;
}

/**
 * @return list<string>
 */
function roleAssignmentQueries(): array
{
    return collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'role_assignments'))->values()->all();
}

it('guarda los nombramientos vigentes, por puesto, y vuelve igual de la caché', function () {
    $user = User::factory()
        ->admin()
        ->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])
        ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])
        ->create();
    RoleAssignment::factory()->for($user)->create(['role' => Role::Coordinador, 'campus_id' => $this->c2->id, 'career_id' => careerKey(21), 'valid_to' => today()->subDay()->toDateString()]);

    $snapshot = app(AuthorizationSnapshots::class)->for(User::find($user->id));

    expect($snapshot->roles())->toBe([Role::Admin, Role::JefeDepartamento, Role::Coordinador])
        ->and($snapshot->scopes(Role::JefeDepartamento))->toEqual([new AssignmentScope(Role::JefeDepartamento, null, fakeKey(10), null, null)])
        ->and($snapshot->scopes(Role::Coordinador))->toEqual([new AssignmentScope(Role::Coordinador, $this->c2->id, null, fakeKey(20), modalityOf('PRES')->id)])
        ->and($snapshot->scopes())->toHaveCount(3)
        ->and(AuthorizationSnapshot::fromArray($snapshot->toArray()))->toEqual($snapshot)
        ->and(fn () => AuthorizationSnapshot::fromArray(['scopes' => [['role' => 'coordinador']]]))->toThrow(UnexpectedValueException::class);
});

it('el snapshot se lee de la caché en la segunda petición', function () {
    $user = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create();

    expect(nextRequestOf($user)->heldRoles())->toContain(Role::Coordinador)
        ->and(Cache::has(snapshotKeyOf($user)))->toBeTrue();

    DB::enableQueryLog();

    expect(nextRequestOf($user)->heldRoles())->toContain(Role::Coordinador)
        ->and(roleAssignmentQueries())->toBe([]);
});

it('un nombramiento nuevo cuenta en la siguiente petición', function () {
    $user = User::factory()->withRole(Role::Docente)->create();
    $before = User::find($user->id);

    expect(nextRequestOf($user)->heldRoles())->not->toContain(Role::Coordinador);

    RoleAssignment::factory()->for($user)->create(['role' => Role::Coordinador, 'campus_id' => $this->c2->id, 'career_id' => careerKey(20)]);

    expect(nextRequestOf($user)->heldRoles())->toContain(Role::Coordinador)
        ->and(app(AuthorizationSnapshots::class)->for($before)->roles())->toBe([Role::Docente]);
});

it('un nombramiento que empieza mañana cuenta desde mañana', function () {
    $user = User::factory()->withRole(Role::Docente)->create();
    RoleAssignment::factory()->for($user)->create([
        'role' => Role::Coordinador, 'campus_id' => $this->c2->id, 'career_id' => careerKey(20), 'valid_from' => today()->addDay()->toDateString(),
    ]);

    expect(nextRequestOf($user)->heldRoles())->not->toContain(Role::Coordinador);

    $this->travelTo(CarbonImmutable::parse('2026-11-03 23:59', 'America/Ciudad_Juarez'));

    expect(nextRequestOf($user)->heldRoles())->not->toContain(Role::Coordinador);

    $this->travelTo(CarbonImmutable::parse('2026-11-04 00:01', 'America/Ciudad_Juarez'));

    expect(nextRequestOf($user)->heldRoles())->toContain(Role::Coordinador);
});

it('sin authz_version cargada lee los nombramientos de la base y no los guarda en caché', function () {
    $user = User::factory()->withRole(Role::Docente)->create();

    expect(app(AuthorizationSnapshots::class)->for($user)->roles())->toBe([Role::Docente])
        ->and(Cache::has(snapshotKeyOf($user)))->toBeFalse();

    RoleAssignment::factory()->for($user)->create(['role' => Role::Coordinador, 'campus_id' => $this->c2->id, 'career_id' => careerKey(20)]);

    expect(app(AuthorizationSnapshots::class)->for($user)->roles())->toBe([Role::Coordinador, Role::Docente]);
});

it('refresh() vuelve a leer authz_version y ve un nombramiento nuevo en la misma petición', function () {
    $user = User::factory()->withRole(Role::Docente)->create();
    $context = nextRequestOf($user);

    expect($context->heldRoles())->not->toContain(Role::Coordinador);

    RoleAssignment::factory()->for($user)->create(['role' => Role::Coordinador, 'campus_id' => $this->c2->id, 'career_id' => careerKey(20)]);

    expect($context->heldRoles())->not->toContain(Role::Coordinador);

    $context->refresh();

    expect($context->heldRoles())->toContain(Role::Coordinador)
        ->and($context->user()->authz_version)->toBe(User::find($user->id)->authz_version)
        ->and($context->user()->isDirty())->toBeFalse();
});

it('una transacción revertida no deja un snapshot con lo que se deshizo', function () {
    $user = User::factory()->withRole(Role::Docente)->create();

    try {
        DB::transaction(function () use ($user): void {
            RoleAssignment::factory()->for($user)->create(['role' => Role::Coordinador, 'campus_id' => $this->c2->id, 'career_id' => careerKey(20)]);

            expect(nextRequestOf($user)->heldRoles())->toContain(Role::Coordinador);

            throw new RuntimeException('se revierte');
        });
    } catch (RuntimeException) {
    }

    expect(Cache::has('authz:user:v'.AuthorizationSnapshots::FORMAT.':'.$user->id.':'.(User::find($user->id)->authz_version + 1)))->toBeFalse();

    // Un cambio confirmado vuelve a la misma authz_version que tuvo la transacción revertida.
    RoleAssignment::factory()->for($user)->create(['role' => Role::JefeProyectoDocente, 'campus_id' => $this->c2->id, 'org_unit_id' => departmentKey(10)]);
    $context = nextRequestOf($user);

    expect($context->heldRoles())->toContain(Role::JefeProyectoDocente)
        ->and($context->heldRoles())->not->toContain(Role::Coordinador);
});

it('un snapshot en caché con un campo faltante o de otro tipo se descarta y se recarga', function (array $changes) {
    $user = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create();
    $key = snapshotKeyOf($user);
    $broken = array_filter(
        [...['role' => 'coordinador', 'campus_id' => $this->c2->id, 'org_unit_id' => null, 'career_id' => careerKey(20), 'modality_id' => modalityOf('PRES')->id], ...$changes],
        fn (mixed $value): bool => $value !== '__drop__',
    );
    Cache::put($key, ['scopes' => [$broken]], now()->addHour());

    $context = nextRequestOf($user);

    expect($context->allows(AcademicLoadPermission::ProposalView, Target::of(modality: modalityOf('PRES')->id, career: fakeKey(20))))->toBeTrue()
        ->and($context->allows(AcademicLoadPermission::ProposalView, Target::of(modality: modalityOf('PRES')->id, career: careerKey(21))))->toBeFalse()
        ->and(Cache::get($key))->toBe(['scopes' => [[
            'role' => 'coordinador', 'campus_id' => $this->c2->id, 'org_unit_id' => null, 'career_id' => fakeKey(20), 'modality_id' => modalityOf('PRES')->id,
        ]]]);
})->with([
    'sin carrera' => [['career_id' => '__drop__']],
    'AZ-S17: sin modalidad (un snapshot de antes de la modalidad)' => [['modality_id' => '__drop__']],
    'campus de otro tipo' => [['campus_id' => 7]],
    'puesto que ya no existe' => [['role' => 'decano']],
]);

it('un snapshot con el formato anterior no se lee: se recarga de la base con el nuevo', function () {
    $user = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create();
    $version = User::find($user->id)->authz_version;
    // Bajo la llave del formato 2, algo que el 3 sí podría leer: una Subdirección de todo el Tec. No se lee, porque la
    // llave cambió con el formato.
    Cache::put("authz:user:v2:{$user->id}:{$version}", ['scopes' => [[
        'role' => 'subdireccion', 'campus_id' => null, 'org_unit_id' => null, 'career_id' => null, 'modality_id' => null,
    ]]], now()->addHour());

    expect(nextRequestOf($user)->heldRoles())->toBe([Role::Coordinador])
        ->and(Cache::has("authz:user:v3:{$user->id}:{$version}"))->toBeTrue();
});

it('un snapshot en caché que no es una lista de nombramientos se descarta y se recarga', function (mixed $cached) {
    $user = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create();
    Cache::put(snapshotKeyOf($user), $cached, now()->addHour());

    expect(nextRequestOf($user)->allows(AcademicLoadPermission::ProposalView, Target::of(modality: modalityOf('PRES')->id, career: careerKey(21))))->toBeFalse()
        ->and(nextRequestOf($user)->heldRoles())->toContain(Role::Coordinador);
})->with([
    'sin scopes' => [[]],
    'scopes que no es lista' => [['scopes' => 'coordinador']],
    'un texto' => ['coordinador'],
]);
