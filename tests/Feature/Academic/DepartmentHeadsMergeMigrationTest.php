<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/*
| La migración que funde `department_heads` en `role_assignments` (3.P8, AZ-S14, confirmado el 3 de noviembre de
| 2026): cada jefatura conserva su nombramiento gemelo (mismo usuario, puesto, departamento, campus e inicio) y manda
| la fecha de fin más temprana. Nunca se crea ni se alarga un nombramiento, cada diferencia va a la bitácora del
| usuario y las jefaturas borradas no se tocan. Después `department_heads` queda de solo lectura y la base rechaza a la
| misma persona dos veces como jefe de proyecto docente del mismo departamento y campus en fechas que se traslapan.
| Se prueba sobre la forma de antes: `down()`, filas con `DB::table()` y `up()`.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-07 12:00', 'America/Ciudad_Juarez'));

    $this->migration = require base_path('app/Modules/Academic/Database/Migrations/2026_11_07_000001_merge_department_heads_into_role_assignments.php');
    $this->migration->down();

    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->systems = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->juan = User::factory()->create(['name' => 'Juan Ruiz']);
    $this->pedro = User::factory()->create(['name' => 'Pedro Soto']);
    $this->activityBase = (int) Activity::max('id');
});

/**
 * Una jefatura con `DB::table()`: el jefe va sin campus y el jefe de proyecto docente con el suyo.
 *
 * @param  array<string, mixed>  $extra
 */
function p8Head(User $user, Department $department, string $kind, ?Campus $campus, string $from, ?string $to = null, array $extra = []): string
{
    return DB::table('department_heads')->insertGetId([
        'department_id' => $department->id,
        'campus_id' => $campus?->id,
        'user_id' => $user->id,
        'kind' => $kind,
        'valid_from' => $from,
        'valid_to' => $to,
        'created_at' => now(),
        'updated_at' => now(),
        ...$extra,
    ]);
}

/**
 * Un nombramiento con `DB::table()`, como lo escribía AppointDepartmentHead para su jefatura.
 */
function p8Assignment(User $user, Role $role, Department $department, ?Campus $campus, string $from, ?string $to = null): string
{
    return DB::table('role_assignments')->insertGetId([
        'user_id' => $user->id,
        'role' => $role->value,
        'campus_id' => $campus?->id,
        'org_unit_id' => $department->id,
        'valid_from' => $from,
        'valid_to' => $to,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Las entradas de bitácora que dejó la migración (o la prueba) desde el inicio de la prueba.
 *
 * @return Collection<int, Activity>
 */
function p8Entries(): Collection
{
    return Activity::query()->where('id', '>', test()->activityBase)->orderBy('id')->get();
}

function p8ValidTo(string $assignmentId): ?string
{
    return DB::table('role_assignments')->where('id', $assignmentId)->value('valid_to');
}

it('conserva el gemelo de cada jefatura y no crea ningún nombramiento', function () {
    p8Head($this->juan, $this->systems, 'jefe', null, '2026-01-01');
    $jefe = p8Assignment($this->juan, Role::JefeDepartamento, $this->systems, null, '2026-01-01');
    p8Head($this->pedro, $this->systems, 'jefe_proyecto_docente', $this->c2, '2026-02-01', '2026-12-31');
    $lead = p8Assignment($this->pedro, Role::JefeProyectoDocente, $this->systems, $this->c2, '2026-02-01', '2026-12-31');
    $before = DB::table('role_assignments')->orderBy('id')->get()->all();

    $this->migration->up();

    expect(DB::table('role_assignments')->orderBy('id')->get()->all())->toEqual($before)
        ->and(p8ValidTo($jefe))->toBeNull()
        ->and(p8ValidTo($lead))->toBe('2026-12-31')
        ->and(p8Entries())->toBeEmpty();
});

it('AZ-S14: si la jefatura terminó antes que su gemelo, el gemelo termina ese día y queda en la bitácora', function (?string $twinEnd) {
    $head = p8Head($this->juan, $this->systems, 'jefe', null, '2026-01-01', '2026-10-31');
    $twin = p8Assignment($this->juan, Role::JefeDepartamento, $this->systems, null, '2026-01-01', $twinEnd);
    $version = $this->juan->fresh()->authz_version;

    $this->migration->up();

    $entry = p8Entries()->sole();

    expect(p8ValidTo($twin))->toBe('2026-10-31')
        ->and($this->juan->fresh()->authz_version)->toBeGreaterThan($version)
        ->and($entry)
        ->subject_type->toBe(User::class)
        ->subject_id->toBe($this->juan->id)
        ->causer_id->toBeNull()
        ->event->toBe('updated')
        ->description->toBe('Terminó el nombramiento de Juan Ruiz como Jefe de departamento académico de Sistemas y Computación el 31/10/2026, el día en que terminó su jefatura (3.P8)')
        ->and($entry->properties->all())->toBe(['role_assignment_id' => $twin, 'department_head_id' => $head])
        ->and($entry->attribute_changes->all())->toBe(['attributes' => ['valid_to' => '2026-10-31'], 'old' => ['valid_to' => $twinEnd]]);
})->with([
    'gemelo sin fin' => [null],
    'gemelo que termina después' => ['2026-12-31'],
]);

it('AZ-S14: nunca alarga: si el gemelo terminó antes que la jefatura, se queda como está', function (?string $headEnd) {
    $head = p8Head($this->juan, $this->systems, 'jefe', null, '2026-01-01', $headEnd);
    $twin = p8Assignment($this->juan, Role::JefeDepartamento, $this->systems, null, '2026-01-01', '2026-10-31');
    $version = $this->juan->fresh()->authz_version;

    $this->migration->up();

    $entry = p8Entries()->sole();

    expect(p8ValidTo($twin))->toBe('2026-10-31')
        ->and($this->juan->fresh()->authz_version)->toBe($version)
        ->and($entry)
        ->subject_id->toBe($this->juan->id)
        ->causer_id->toBeNull()
        ->event->toBe('skipped')
        ->description->toBe('La jefatura de Juan Ruiz como Jefe de departamento académico de Sistemas y Computación termina después que su nombramiento (31/10/2026): se quedó la fecha más temprana (3.P8)')
        ->and($entry->properties->all())->toBe(['role_assignment_id' => $twin, 'department_head_id' => $head]);
})->with([
    'jefatura sin fin' => [null],
    'jefatura que termina después' => ['2026-12-31'],
]);

it('AZ-S14: una jefatura sin gemelo no pasa y lo deja dicho en la bitácora de su usuario', function () {
    $head = p8Head($this->juan, $this->systems, 'jefe', null, '2026-01-01');
    $pedros = p8Assignment($this->pedro, Role::JefeDepartamento, $this->systems, null, '2026-03-01');
    $lead = p8Head($this->juan, $this->systems, 'jefe_proyecto_docente', $this->c2, '2026-01-01', '2026-06-30');
    // Mismo usuario, puesto, departamento e inicio, pero de otro campus: no es su gemelo.
    $otherCampus = p8Assignment($this->juan, Role::JefeProyectoDocente, $this->systems, $this->c1, '2026-01-01', '2026-12-31');
    $before = DB::table('role_assignments')->orderBy('id')->get()->all();

    $this->migration->up();

    $entries = p8Entries();

    expect(DB::table('role_assignments')->orderBy('id')->get()->all())->toEqual($before)
        ->and(DB::table('role_assignments')->where('user_id', $this->juan->id)->pluck('id')->all())->toBe([$otherCampus])
        ->and(p8ValidTo($pedros))->toBeNull()
        ->and($entries)->toHaveCount(2)
        ->and($entries->pluck('subject_id')->unique()->all())->toBe([$this->juan->id])
        ->and($entries->pluck('event')->unique()->all())->toBe(['skipped'])
        ->and($entries->pluck('causer_id')->filter()->all())->toBe([])
        ->and($entries->map(fn (Activity $entry): array => $entry->properties->all())->all())->toEqualCanonicalizing([
            ['department_head_id' => $head],
            ['department_head_id' => $lead],
        ])
        ->and($entries->pluck('description')->all())->toEqualCanonicalizing([
            'La jefatura de Juan Ruiz como Jefe de departamento académico de Sistemas y Computación, del 01/01/2026, no tenía nombramiento: no pasó a role_assignments (3.P8)',
            'La jefatura de Juan Ruiz como Jefe de proyecto docente de Sistemas y Computación en C2, del 01/01/2026 al 30/06/2026, no tenía nombramiento: no pasó a role_assignments (3.P8)',
        ]);
});

it('AZ-S14: el nombre del departamento y del campus salen aunque estén borrados', function () {
    p8Head($this->juan, $this->systems, 'jefe_proyecto_docente', $this->c2, '2026-01-01');
    $this->systems->delete();
    $this->c2->delete();

    $this->migration->up();

    expect(p8Entries()->sole()->description)
        ->toBe('La jefatura de Juan Ruiz como Jefe de proyecto docente de Sistemas y Computación en C2, del 01/01/2026, no tenía nombramiento: no pasó a role_assignments (3.P8)');
});

it('AZ-S14: una jefatura borrada no se toca ni deja bitácora', function () {
    p8Head($this->juan, $this->systems, 'jefe', null, '2026-01-01', '2026-10-31', ['deleted_at' => '2026-11-01 08:00:00']);
    $twin = p8Assignment($this->juan, Role::JefeDepartamento, $this->systems, null, '2026-01-01');
    p8Head($this->pedro, $this->systems, 'jefe_proyecto_docente', $this->c2, '2026-01-01', null, ['deleted_at' => '2026-11-01 08:00:00']);

    $this->migration->up();

    expect(p8ValidTo($twin))->toBeNull()
        ->and(DB::table('role_assignments')->where('user_id', $this->pedro->id)->exists())->toBeFalse()
        ->and(p8Entries())->toBeEmpty();
});

it('es idempotente: correrla otra vez no cambia nada ni repite la bitácora', function () {
    p8Head($this->juan, $this->systems, 'jefe', null, '2026-01-01', '2026-10-31');
    p8Assignment($this->juan, Role::JefeDepartamento, $this->systems, null, '2026-01-01');
    p8Head($this->pedro, $this->systems, 'jefe_proyecto_docente', $this->c2, '2026-01-01');
    p8Assignment($this->pedro, Role::JefeProyectoDocente, $this->systems, $this->c2, '2026-01-01', '2026-10-31');
    p8Head($this->pedro, $this->systems, 'jefe_proyecto_docente', $this->c1, '2026-01-01');

    $this->migration->up();
    $assignments = DB::table('role_assignments')->orderBy('id')->get()->all();
    $entries = p8Entries()->count();

    $this->migration->up();

    expect($entries)->toBe(3)
        ->and(p8Entries())->toHaveCount(3)
        ->and(DB::table('role_assignments')->orderBy('id')->get()->all())->toEqual($assignments);
});

it('department_heads queda de solo lectura: altas, cambios, bajas y vaciado fallan', function (string $change) {
    $head = p8Head($this->juan, $this->systems, 'jefe', null, '2026-01-01');

    $this->migration->up();

    expect(fn () => DB::transaction(fn () => match ($change) {
        'insert' => p8Head($this->pedro, $this->systems, 'jefe', null, '2030-01-01'),
        'update' => DB::table('department_heads')->where('id', $head)->update(['valid_to' => '2026-12-31']),
        'delete' => DB::table('department_heads')->where('id', $head)->delete(),
        'truncate' => DB::statement('truncate department_heads'),
    }))->toThrow(QueryException::class, 'department_heads es histórica desde 3.P8');
})->with(['insert', 'update', 'delete', 'truncate']);

it('la misma persona no es dos veces jefe de proyecto docente del mismo departamento y campus en fechas que se traslapan', function () {
    $this->migration->up();

    p8Assignment($this->juan, Role::JefeProyectoDocente, $this->systems, $this->c2, '2026-01-01', '2026-06-30');

    expect(fn () => DB::transaction(fn () => p8Assignment($this->juan, Role::JefeProyectoDocente, $this->systems, $this->c2, '2026-06-30')))
        ->toThrow(function (QueryException $exception): void {
            expect((string) ($exception->errorInfo[0] ?? $exception->getCode()))->toBe('23P01')
                ->and($exception->getMessage())->toContain('role_assignments_jpd_no_duplicate');
        });

    p8Assignment($this->juan, Role::JefeProyectoDocente, $this->systems, $this->c1, '2026-03-01');
    p8Assignment($this->juan, Role::JefeProyectoDocente, $this->systems, $this->c2, '2026-07-01');
    p8Assignment($this->pedro, Role::JefeProyectoDocente, $this->systems, $this->c2, '2026-03-01');

    expect(DB::table('role_assignments')->where('role', Role::JefeProyectoDocente->value)->count())->toBe(4);
});

it('se detiene y lo dice si ya hay jefes de proyecto docente repetidos que se traslapan', function () {
    p8Assignment($this->juan, Role::JefeProyectoDocente, $this->systems, $this->c2, '2026-01-01', '2026-06-30');
    p8Assignment($this->juan, Role::JefeProyectoDocente, $this->systems, $this->c2, '2026-06-01');
    p8Head($this->pedro, $this->systems, 'jefe', null, '2026-01-01', '2026-10-31');
    $twin = p8Assignment($this->pedro, Role::JefeDepartamento, $this->systems, null, '2026-01-01');

    expect(fn () => $this->migration->up())->toThrow(
        RuntimeException::class,
        'Hay jefes de proyecto docente repetidos que se traslapan: Juan Ruiz en Sistemas y Computación (C2). Termina uno de cada par antes de migrar.',
    );

    expect(p8ValidTo($twin))->toBeNull()
        ->and(p8Entries())->toBeEmpty();

    p8Head($this->juan, $this->systems, 'jefe_proyecto_docente', $this->c1, '2027-01-01');
    expect(DB::table('department_heads')->count())->toBe(2);
});

it('down() vuelve a permitir escribir en department_heads y quita la exclusión', function () {
    $this->migration->up();
    $this->migration->down();

    $head = p8Head($this->juan, $this->systems, 'jefe', null, '2026-01-01');
    DB::table('department_heads')->where('id', $head)->update(['valid_to' => '2026-12-31']);
    p8Assignment($this->juan, Role::JefeProyectoDocente, $this->systems, $this->c2, '2026-01-01');
    p8Assignment($this->juan, Role::JefeProyectoDocente, $this->systems, $this->c2, '2026-02-01');

    expect(DB::table('department_heads')->where('id', $head)->value('valid_to'))->toBe('2026-12-31')
        ->and(DB::table('role_assignments')->where('user_id', $this->juan->id)->count())->toBe(2);
});
