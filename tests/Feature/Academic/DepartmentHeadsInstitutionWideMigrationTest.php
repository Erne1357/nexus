<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\ScopedContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role as SpatieRole;

/*
| La migración que vuelve al jefe de departamento uno por departamento para todo el Tec (confirmado el 30 de
| octubre de 2026) convierte los datos que ya existen: el de la base de desarrollo con el masivo tiene un jefe por
| departamento en Campus I y otro en Campus II, y una Subdirección por campus. La prueba regresa el esquema al de
| antes con down(), siembra ese estado con DB::table y corre up().
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 12:00', 'America/Ciudad_Juarez'));

    $this->migration = require base_path('app/Modules/Academic/Database/Migrations/2026_10_30_000101_make_department_heads_institution_wide.php');
    $this->migration->down();

    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->ead = Campus::factory()->create(['code' => 'EAD']);
});

/**
 * Una fila de `department_heads` con la forma de antes (todas con campus) y, si `$withScope`, su alcance como lo
 * escribía AppointDepartmentHead. Devuelve el id de la fila y el del alcance.
 *
 * @param  array<string, mixed>  $extra
 * @return array{head: string, scope: ?string}
 */
function oldShapeHead(User $user, Department $department, Campus $campus, string $kind, string $from, ?string $to, string $createdAt, array $extra = [], bool $withScope = true): array
{
    $head = DB::table('department_heads')->insertGetId([
        'department_id' => $department->id,
        'campus_id' => $campus->id,
        'user_id' => $user->id,
        'kind' => $kind,
        'valid_from' => $from,
        'valid_to' => $to,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
        ...$extra,
    ]);

    $role = $kind === 'jefe' ? Role::JefeDepartamento : Role::JefeProyectoDocente;
    $user->assignRole(SpatieRole::findOrCreate($role->value));

    $scope = $withScope ? oldShapeScope($user, $role, $campus, $department, $from, $to, $createdAt) : null;

    return ['head' => $head, 'scope' => $scope];
}

function oldShapeScope(User $user, Role $role, Campus $campus, ?Department $department, string $from, ?string $to, string $createdAt): string
{
    $user->assignRole(SpatieRole::findOrCreate($role->value));

    return DB::table('role_scopes')->insertGetId([
        'user_id' => $user->id,
        'role' => $role->value,
        'campus_id' => $campus->id,
        'department_id' => $department?->id,
        'valid_from' => $from,
        'valid_to' => $to,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

/**
 * @return object{campus_id: ?string, valid_to: ?string, deleted_at: ?string}
 */
function headRow(string $id): object
{
    return DB::table('department_heads')->where('id', $id)->first();
}

function scopeRow(string $id): ?object
{
    return DB::table('role_scopes')->where('id', $id)->first();
}

it('deja un solo jefe vigente por departamento, sin campus: el más antiguo; los demás terminan ayer o se borran, con su alcance', function () {
    $sc = Department::factory()->create(['code' => 'SC']);
    $cb = Department::factory()->create(['code' => 'CB']);
    $ii = Department::factory()->create(['code' => 'II']);
    [$a, $b, $c, $f, $g, $h] = User::factory()->count(6)->create()->all();

    // Sistemas: dos jefes vigentes desde el mismo día (empate: gana el primero que se creó) y uno por empezar.
    $kept = oldShapeHead($a, $sc, $this->c1, 'jefe', '2025-01-01', null, '2025-01-01 08:00:00');
    $ended = oldShapeHead($b, $sc, $this->c2, 'jefe', '2025-01-01', null, '2025-01-01 09:00:00');
    $future = oldShapeHead($c, $sc, $this->ead, 'jefe', '2026-11-15', null, '2026-10-01 08:00:00');
    // Ciencias Básicas: los dos empiezan hoy; el segundo se borra con su alcance.
    $today = oldShapeHead($g, $cb, $this->c1, 'jefe', '2026-10-30', null, '2026-10-30 08:00:00');
    $todayToo = oldShapeHead($h, $cb, $this->c2, 'jefe', '2026-10-30', null, '2026-10-30 08:00:00');
    // Industrial: un solo jefe, en Campus II.
    $only = oldShapeHead($f, $ii, $this->c2, 'jefe', '2026-01-01', null, '2026-01-01 08:00:00');

    $this->migration->up();

    expect(headRow($kept['head']))->campus_id->toBeNull()->deleted_at->toBeNull()->valid_to->toBeNull()
        ->and(scopeRow($kept['scope']))->campus_id->toBeNull()->valid_to->toBeNull()
        ->and(headRow($ended['head']))->campus_id->toBeNull()->valid_to->toBe('2026-10-29')->deleted_at->not->toBeNull()
        ->and(scopeRow($ended['scope']))->campus_id->toBe($this->c2->id)->valid_to->toBe('2026-10-29')
        ->and(headRow($future['head']))->campus_id->toBeNull()->valid_to->toBeNull()->deleted_at->not->toBeNull()
        ->and(scopeRow($future['scope']))->toBeNull()
        ->and(headRow($today['head']))->campus_id->toBeNull()->deleted_at->toBeNull()
        ->and(scopeRow($today['scope']))->campus_id->toBeNull()
        ->and(headRow($todayToo['head']))->deleted_at->not->toBeNull()
        ->and(scopeRow($todayToo['scope']))->toBeNull()
        ->and(headRow($only['head']))->campus_id->toBeNull()->deleted_at->toBeNull()
        ->and(scopeRow($only['scope']))->campus_id->toBeNull();

    $current = DB::table('department_heads')->where('kind', 'jefe')->whereNull('deleted_at')
        ->where('valid_from', '<=', '2026-10-30')->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', '2026-10-30'))
        ->pluck('user_id', 'department_id')->all();

    expect($current)->toBe([$sc->id => $a->id, $cb->id => $g->id, $ii->id => $f->id]);

    $context = fn (User $user): ScopedContext => app(ScopedContext::class)->forUser($user->fresh());

    expect($context($a)->covers(Role::JefeDepartamento, $this->c1, $sc->id))->toBeTrue()
        ->and($context($a)->covers(Role::JefeDepartamento, $this->c2, $sc->id))->toBeTrue()
        ->and($context($b)->has(Role::JefeDepartamento))->toBeFalse()
        ->and($context($c)->has(Role::JefeDepartamento))->toBeFalse()
        ->and($context($f)->covers(Role::JefeDepartamento, $this->c1, $ii->id))->toBeTrue();
});

it('los jefes que ya terminaron pasan a no tener campus si no chocan con otro; si chocan, se quedan borrados', function () {
    $sc = Department::factory()->create(['code' => 'SC']);
    [$a, $d, $e, $k] = User::factory()->count(4)->create()->all();

    $kept = oldShapeHead($a, $sc, $this->c1, 'jefe', '2025-01-01', null, '2025-01-01 08:00:00');
    $before = oldShapeHead($d, $sc, $this->c2, 'jefe', '2024-01-01', '2024-12-31', '2024-01-01 08:00:00');
    $overlapping = oldShapeHead($e, $sc, $this->ead, 'jefe', '2024-06-01', '2025-02-28', '2024-06-01 08:00:00');
    $alreadyDeleted = oldShapeHead($k, $sc, $this->c2, 'jefe', '2025-01-01', null, '2025-01-01 07:00:00', ['deleted_at' => '2025-02-01 08:00:00'], withScope: false);
    $deletedAt = headRow($alreadyDeleted['head'])->deleted_at;

    $this->migration->up();

    expect(headRow($kept['head']))->campus_id->toBeNull()->deleted_at->toBeNull()
        ->and(headRow($before['head']))->campus_id->toBeNull()->deleted_at->toBeNull()->valid_to->toBe('2024-12-31')
        ->and(scopeRow($before['scope']))->campus_id->toBeNull()->valid_to->toBe('2024-12-31')
        ->and(headRow($overlapping['head']))->campus_id->toBeNull()->deleted_at->not->toBeNull()->valid_to->toBe('2025-02-28')
        ->and(scopeRow($overlapping['scope']))->campus_id->toBe($this->ead->id)
        ->and(headRow($alreadyDeleted['head']))->campus_id->toBeNull()->deleted_at->toBe($deletedAt);
});

it('un relevo que no se traslapa con el jefe que se conserva se queda, sin campus', function () {
    $ind = Department::factory()->create(['code' => 'IND']);
    [$l, $m] = User::factory()->count(2)->create()->all();

    $current = oldShapeHead($l, $ind, $this->c1, 'jefe', '2026-01-01', '2026-12-31', '2026-01-01 08:00:00');
    $next = oldShapeHead($m, $ind, $this->c2, 'jefe', '2027-01-01', null, '2026-10-01 08:00:00');

    $this->migration->up();

    expect(headRow($current['head']))->campus_id->toBeNull()->deleted_at->toBeNull()
        ->and(headRow($next['head']))->campus_id->toBeNull()->deleted_at->toBeNull()->valid_to->toBeNull()
        ->and(scopeRow($next['scope']))->campus_id->toBeNull()->valid_from->toBe('2027-01-01');
});

it('no toca a los jefes de proyecto docente ni otros alcances de jefe que no son de una jefatura', function () {
    $sc = Department::factory()->create(['code' => 'SC']);
    [$a, $i, $j] = User::factory()->count(3)->create()->all();

    oldShapeHead($a, $sc, $this->c1, 'jefe', '2025-01-01', null, '2025-01-01 08:00:00');
    $manual = oldShapeScope($a, Role::JefeDepartamento, $this->c2, $sc, '2025-03-01', null, '2025-03-01 08:00:00');
    $lead1 = oldShapeHead($i, $sc, $this->c1, 'jefe_proyecto_docente', '2025-01-01', null, '2025-01-01 08:00:00');
    $lead2 = oldShapeHead($j, $sc, $this->c2, 'jefe_proyecto_docente', '2025-01-01', null, '2025-01-01 08:00:00');

    $this->migration->up();

    expect(scopeRow($manual))->campus_id->toBe($this->c2->id)->valid_to->toBeNull()
        ->and(headRow($lead1['head']))->campus_id->toBe($this->c1->id)->deleted_at->toBeNull()
        ->and(scopeRow($lead1['scope']))->campus_id->toBe($this->c1->id)
        ->and(headRow($lead2['head']))->campus_id->toBe($this->c2->id)->deleted_at->toBeNull()
        ->and(scopeRow($lead2['scope']))->campus_id->toBe($this->c2->id);
});

it('la Subdirección queda con un alcance de todo el Tec: el que vale hoy se conserva y se borran los que se le traslapan', function () {
    [$s1, $s2, $s3, $s4] = User::factory()->count(4)->create()->all();

    $only = oldShapeScope($s1, Role::Subdireccion, $this->c1, null, '2026-01-01', null, '2026-01-01 08:00:00');
    $first = oldShapeScope($s2, Role::Subdireccion, $this->c1, null, '2026-01-01', null, '2026-01-01 08:00:00');
    $second = oldShapeScope($s2, Role::Subdireccion, $this->c2, null, '2026-01-01', null, '2026-01-01 09:00:00');
    $expired = oldShapeScope($s3, Role::Subdireccion, $this->c1, null, '2025-01-01', '2025-12-31', '2025-01-01 08:00:00');
    $currentC2 = oldShapeScope($s3, Role::Subdireccion, $this->c2, null, '2025-06-01', null, '2025-06-01 08:00:00');
    $untilDecember = oldShapeScope($s4, Role::Subdireccion, $this->c1, null, '2026-01-01', '2026-12-31', '2026-01-01 08:00:00');
    $fromJanuary = oldShapeScope($s4, Role::Subdireccion, $this->c2, null, '2027-01-01', null, '2026-10-01 08:00:00');

    $this->migration->up();

    expect(scopeRow($only))->campus_id->toBeNull()
        ->and(scopeRow($first))->campus_id->toBeNull()
        ->and(scopeRow($second))->toBeNull()
        ->and(scopeRow($currentC2))->campus_id->toBeNull()
        ->and(scopeRow($expired))->toBeNull()
        ->and(scopeRow($untilDecember))->campus_id->toBeNull()
        ->and(scopeRow($fromJanuary))->campus_id->toBeNull()
        ->and(DB::table('role_scopes')->where('role', 'subdireccion')->whereNotNull('campus_id')->exists())->toBeFalse();

    foreach ([$s1, $s2, $s3, $s4] as $user) {
        $context = app(ScopedContext::class)->forUser($user->fresh());

        expect($context->covers(Role::Subdireccion, $this->c1))->toBeTrue()
            ->and($context->covers(Role::Subdireccion, $this->c2))->toBeTrue()
            ->and($context->scopes(Role::Subdireccion))->toHaveCount(1);
    }
});

it('después de convertir, la base ya exige la forma nueva', function () {
    $sc = Department::factory()->create(['code' => 'SC']);
    [$a, $b] = User::factory()->count(2)->create()->all();
    oldShapeHead($a, $sc, $this->c1, 'jefe', '2025-01-01', null, '2025-01-01 08:00:00');

    $this->migration->up();

    $insert = fn (?string $campus, string $kind = 'jefe') => DB::transaction(fn () => DB::table('department_heads')->insert([
        'department_id' => $sc->id, 'campus_id' => $campus, 'user_id' => $b->id, 'kind' => $kind, 'valid_from' => '2026-10-30',
    ]));

    expect(fn () => $insert(null))->toThrow(QueryException::class, 'department_heads_one_jefe')
        ->and(fn () => $insert($this->c2->id))->toThrow(QueryException::class, 'department_heads_campus_check')
        ->and(fn () => $insert(null, 'jefe_proyecto_docente'))->toThrow(QueryException::class, 'department_heads_campus_check');
});

it('no se puede regresar con jefes sin campus: no hay de dónde sacar su campus', function () {
    $sc = Department::factory()->create(['code' => 'SC']);
    oldShapeHead(User::factory()->create(), $sc, $this->c1, 'jefe', '2025-01-01', null, '2025-01-01 08:00:00');
    $this->migration->up();

    expect(fn () => $this->migration->down())->toThrow(RuntimeException::class, 'sin campus');
});
