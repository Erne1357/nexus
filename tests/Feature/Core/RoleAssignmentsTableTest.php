<?php

use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;

/*
| La tabla de nombramientos (ADR-0018): quién tiene qué puesto, sobre qué campus, unidad o carrera y desde cuándo.
| Qué cubre cada uno lo decide Actor; aquí se prueban la tabla, sus restricciones, su trigger y la migración
| que la sacó de `role_scopes`.
*/

/**
 * Lo que la base responde a un insert que viola una exclusión: SQLSTATE 23P01 con el nombre de la restricción.
 */
function expectExclusion(Closure $insert, string $constraint): void
{
    expect(fn () => DB::transaction($insert))->toThrow(function (QueryException $exception) use ($constraint): void {
        expect((string) ($exception->errorInfo[0] ?? $exception->getCode()))->toBe('23P01')
            ->and($exception->getMessage())->toContain($constraint);
    });
}

function academicUnit(): string
{
    return app(OrgTree::class)->idOf(OrgTree::ACADEMIC);
}

/**
 * Una fila de `role_scopes` con la forma de antes de la migración de nombramientos.
 *
 * @param  array<string, mixed>  $attributes
 */
function oldShapeRoleScope(User $user, Role $role, array $attributes = []): string
{
    return DB::table('role_scopes')->insertGetId([
        'user_id' => $user->id,
        'role' => $role->value,
        'valid_from' => '2026-01-01',
        'created_at' => '2026-01-01 08:00',
        'updated_at' => '2026-01-01 08:00',
        ...$attributes,
    ]);
}

it('guarda el nombramiento de un puesto en un campus, una unidad y una carrera', function () {
    $user = User::factory()->create();
    $campus = Campus::factory()->create();

    DB::table('role_assignments')->insert([
        'user_id' => $user->id,
        'role' => Role::Coordinador->value,
        'campus_id' => $campus->id,
        'org_unit_id' => departmentKey(7),
        'career_id' => careerKey(12),
        'valid_from' => '2026-08-01',
        'valid_to' => null,
    ]);

    expect(DB::table('role_assignments')->where('user_id', $user->id)->first())
        ->campus_id->toBe($campus->id)
        ->org_unit_id->toBe(fakeKey(7))
        ->career_id->toBe(fakeKey(12))
        ->valid_to->toBeNull();
});

it('rechaza un nombramiento de un usuario, campus, unidad o carrera que no existe', function (string $column) {
    $row = [
        'user_id' => User::factory()->create()->id,
        'role' => Role::JefeProyectoDocente->value,
        'campus_id' => Campus::factory()->create()->id,
        'org_unit_id' => departmentKey(10),
        'valid_from' => '2026-08-01',
    ];

    expect(fn () => DB::table('role_assignments')->insert([...$row, $column => fakeKey(999_999)]))
        ->toThrow(QueryException::class, 'foreign key');
})->with(['user_id', 'campus_id', 'org_unit_id', 'career_id']);

it('acepta una unidad que no es un departamento académico', function () {
    DB::table('role_assignments')->insert([
        'user_id' => User::factory()->create()->id,
        'role' => Role::Subdireccion->value,
        'org_unit_id' => academicUnit(),
        'valid_from' => '2026-08-01',
    ]);

    expect(RoleAssignment::sole()->orgUnit->code)->toBe(OrgTree::ACADEMIC);
});

it('rechaza una vigencia que termina antes de empezar y acepta una de un solo día', function () {
    $row = [
        'user_id' => User::factory()->create()->id,
        'role' => Role::Docente->value,
        'valid_from' => '2026-08-01',
    ];

    DB::table('role_assignments')->insert([...$row, 'valid_to' => '2026-08-01']);

    expect(fn () => DB::table('role_assignments')->insert([...$row, 'valid_to' => '2026-07-31']))
        ->toThrow(QueryException::class, 'role_assignments_validity_check');
});

it('rechaza un nombramiento de admin con campus, unidad o carrera', function () {
    $user = User::factory()->create();

    expect(fn () => DB::table('role_assignments')->insert([
        'user_id' => $user->id,
        'role' => Role::Admin->value,
        'campus_id' => Campus::factory()->create()->id,
        'valid_from' => '2026-08-01',
    ]))->toThrow(QueryException::class, 'role_assignments_admin_unscoped');
});

describe('puestos exclusivos (AZ-S7)', function () {
    it('rechaza un segundo jefe vigente de la misma unidad', function () {
        $unit = departmentKey(10);
        RoleAssignment::factory()->create(['role' => Role::JefeDepartamento, 'org_unit_id' => $unit, 'valid_from' => '2026-08-01']);

        expectExclusion(
            fn () => RoleAssignment::factory()->create(['role' => Role::JefeDepartamento, 'org_unit_id' => $unit, 'valid_from' => '2026-09-01', 'valid_to' => '2026-12-31']),
            'role_assignments_one_jefe',
        );
    });

    it('rechaza dos subdirecciones vigentes a la vez, aunque una lleve campus', function () {
        $campus = Campus::factory()->create();
        RoleAssignment::factory()->create(['role' => Role::Subdireccion, 'org_unit_id' => academicUnit(), 'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31']);

        expectExclusion(
            fn () => RoleAssignment::factory()->create(['role' => Role::Subdireccion, 'org_unit_id' => academicUnit(), 'campus_id' => $campus->id, 'valid_from' => '2026-12-31']),
            'role_assignments_one_subdireccion',
        );
    });

    it('acepta un jefe de otra unidad, el que empieza al día siguiente de que el otro termina y varios jefes de proyecto docente de la misma unidad', function () {
        $campus = Campus::factory()->create();
        $unit = departmentKey(10);

        RoleAssignment::factory()->create(['role' => Role::JefeDepartamento, 'org_unit_id' => $unit, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-30']);
        RoleAssignment::factory()->create(['role' => Role::JefeDepartamento, 'org_unit_id' => $unit, 'valid_from' => '2026-07-01']);
        RoleAssignment::factory()->create(['role' => Role::JefeDepartamento, 'org_unit_id' => departmentKey(11), 'valid_from' => '2026-01-01']);
        RoleAssignment::factory()->count(2)->create(['role' => Role::JefeProyectoDocente, 'org_unit_id' => $unit, 'campus_id' => $campus->id, 'valid_from' => '2026-01-01']);

        expect(RoleAssignment::count())->toBe(5);
    });
});

describe('columnas de alcance por puesto (role_assignments_scope_complete)', function () {
    $migrationPath = 'app/Modules/Core/Database/Migrations/2026_11_08_000001_add_scope_check_to_role_assignments.php';

    /**
     * Un renglón con todo lo que `scopeColumns()` del puesto pide, menos `$without`.
     *
     * @param  list<string>  $without
     * @return array<string, mixed>
     */
    function scopedRow(Role $role, array $without = []): array
    {
        $values = [
            'campus_id' => Campus::factory()->create()->id,
            'org_unit_id' => $role === Role::Subdireccion ? academicUnit() : departmentKey(10),
            'career_id' => careerKey(20),
        ];

        return [
            'user_id' => User::factory()->create()->id,
            'role' => $role->value,
            'valid_from' => '2026-08-01',
            ...collect($role->scopeColumns())->diff($without)->mapWithKeys(fn (string $column): array => [$column => $values[$column]])->all(),
        ];
    }

    $missing = [];
    $complete = [];
    foreach (Role::cases() as $role) {
        $complete[$role->value] = $role;
        foreach ($role->scopeColumns() as $column) {
            $missing["{$role->value} sin {$column}"] = [$role, $column];
        }
    }

    it('la base rechaza un nombramiento al que le falta una columna que su puesto pide', function (Role $role, string $column) {
        expect(fn () => DB::table('role_assignments')->insert(scopedRow($role, [$column])))
            ->toThrow(QueryException::class, 'role_assignments_scope_complete');
    })->with($missing);

    it('la base acepta cada puesto con lo que pide', function (Role $role) {
        DB::table('role_assignments')->insert(scopedRow($role));

        expect(DB::table('role_assignments')->count())->toBe(1);
    })->with($complete);

    it('la base acepta una columna de más, que solo acota', function () {
        DB::table('role_assignments')->insert([...scopedRow(Role::JefeDepartamento), 'campus_id' => Campus::factory()->create()->id]);

        expect(DB::table('role_assignments')->count())->toBe(1);
    });

    it('la expresión del CHECK pide lo mismo que Role::scopeColumns()', function () {
        // Cada puesto, con exactamente sus columnas, pasa; con una menos, no (arriba). Aquí, las columnas que pide el
        // enum contra las que la migración congeló.
        expect(collect(Role::cases())->mapWithKeys(fn (Role $role): array => [$role->value => $role->scopeColumns()])->all())->toBe([
            'admin' => [],
            'subdireccion' => ['org_unit_id'],
            'jefe_departamento' => ['org_unit_id'],
            'jefe_proyecto_docente' => ['campus_id', 'org_unit_id'],
            'coordinador' => ['campus_id', 'career_id'],
            'docente' => [],
        ]);
    });

    it('Q2: la migración se detiene y lista los ids si ya hay nombramientos incompletos', function () use ($migrationPath) {
        DB::statement('alter table role_assignments drop constraint role_assignments_scope_complete');
        $id = DB::table('role_assignments')->insertGetId(scopedRow(Role::Coordinador, ['career_id']));
        $migration = require base_path($migrationPath);

        expect(fn () => $migration->up())->toThrow(RuntimeException::class, $id)
            ->and(DB::table('pg_constraint')->where('conname', 'role_assignments_scope_complete')->exists())->toBeFalse()
            ->and(DB::table('role_assignments')->where('id', $id)->exists())->toBeTrue();

        DB::table('role_assignments')->where('id', $id)->delete();
        $migration->up();
    });
});

describe('versión de autorización del usuario', function () {
    it('dar, cambiar o quitar un nombramiento sube authz_version de su usuario', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();

        expect($user->fresh()->authz_version)->toBe(0);

        $assignment = RoleAssignment::factory()->for($user)->create();
        expect($user->fresh()->authz_version)->toBe(1);

        $assignment->update(['valid_to' => today()->addMonth()->toDateString()]);
        expect($user->fresh()->authz_version)->toBe(2);

        $assignment->delete();
        expect($user->fresh()->authz_version)->toBe(3)
            ->and($other->fresh()->authz_version)->toBe(0);
    });

    it('pasar un nombramiento a otro usuario sube la versión de los dos', function () {
        [$from, $to] = User::factory()->count(2)->create();
        $assignment = RoleAssignment::factory()->for($from)->create();

        $assignment->update(['user_id' => $to->id]);

        expect($from->fresh()->authz_version)->toBe(2)
            ->and($to->fresh()->authz_version)->toBe(1);
    });

    it('el usuario empieza sin último perfil', function () {
        expect(User::factory()->create()->fresh())->last_role->toBeNull()->authz_version->toBe(0);
    });
});

describe('migración desde role_scopes', function () {
    beforeEach(function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00', 'America/Ciudad_Juarez'));
        // Primero las migraciones posteriores: el CHECK de columnas de alcance y la que agregó la exclusión de jefes de proyecto docente a `role_assignments`.
        (require base_path('app/Modules/Core/Database/Migrations/2026_11_08_000001_add_scope_check_to_role_assignments.php'))->down();
        (require base_path('app/Modules/Academic/Database/Migrations/2026_11_07_000001_merge_department_heads_into_role_assignments.php'))->down();
        $this->migration = require base_path('app/Modules/Core/Database/Migrations/2026_11_04_000001_create_role_assignments_from_role_scopes.php');
        $this->migration->down();

        // Las tablas de spatie ya no existen: se recrean con la migración histórica, como estaban al migrar.
        (require base_path('app/Modules/Core/Database/Migrations/2026_10_27_000006_create_permission_tables.php'))->up();
    });

    it('la migración pasa Subdirección a Subdirección Académica y da nombramiento sin alcance a cada admin', function () {
        expect(Schema::hasTable('role_assignments'))->toBeFalse()
            ->and(Schema::hasColumns('users', ['authz_version', 'last_role']))->toBeFalse();

        $admin = User::factory()->create();
        $roleId = DB::table('roles')->insertGetId(['name' => 'admin', 'guard_name' => 'web']);
        DB::table('model_has_roles')->insert(['role_id' => $roleId, 'model_type' => $admin->getMorphClass(), 'model_id' => $admin->id]);
        $subdireccion = User::factory()->create();
        $jefe = User::factory()->create();
        $subdireccionScope = oldShapeRoleScope($subdireccion, Role::Subdireccion);
        $jefeScope = oldShapeRoleScope($jefe, Role::JefeDepartamento, ['department_id' => departmentKey(10)]);

        $this->migration->up();

        $adminAssignment = RoleAssignment::query()->where('user_id', $admin->id)->sole();

        expect(RoleAssignment::find($subdireccionScope)->org_unit_id)->toBe(academicUnit())
            ->and(RoleAssignment::find($jefeScope)->org_unit_id)->toBe(fakeKey(10))
            ->and($adminAssignment)
            ->role->toBe(Role::Admin)
            ->campus_id->toBeNull()
            ->org_unit_id->toBeNull()
            ->career_id->toBeNull()
            ->valid_to->toBeNull()
            ->and($adminAssignment->valid_from->toDateString())->toBe('2026-11-04')
            ->and(RoleAssignment::query()->where('role', Role::Admin)->count())->toBe(1)
            ->and(User::query()->pluck('authz_version')->unique()->all())->toBe([0])
            ->and(Schema::hasTable('role_scopes'))->toBeFalse();
    });

    it('la migración deja un solo nombramiento por unidad en los puestos exclusivos y deja en la bitácora los que elimina', function () {
        $c2 = Campus::factory()->create(['code' => 'C2']);
        [$subdir, $review, $before] = User::factory()->count(3)->create();
        [$jefe, $otherJefe] = User::factory()->count(2)->create();

        $kept = oldShapeRoleScope($subdir, Role::Subdireccion, ['valid_from' => '2026-01-01']);
        $clashing = oldShapeRoleScope($review, Role::Subdireccion, ['campus_id' => $c2->id, 'valid_from' => '2026-10-29']);
        $ended = oldShapeRoleScope($before, Role::Subdireccion, ['valid_from' => '2025-01-01', 'valid_to' => '2025-12-31']);
        $keptJefe = oldShapeRoleScope($jefe, Role::JefeDepartamento, ['department_id' => departmentKey(10), 'valid_from' => '2026-02-01']);
        $clashingJefe = oldShapeRoleScope($otherJefe, Role::JefeDepartamento, ['department_id' => departmentKey(10), 'campus_id' => $c2->id, 'valid_from' => '2026-03-01']);

        $this->migration->up();

        $entry = Activity::query()->where('subject_id', $review->id)->sole();

        expect(RoleAssignment::query()->pluck('id')->sort()->values()->all())->toBe(collect([$kept, $ended, $keptJefe])->sort()->values()->all())
            ->and(RoleAssignment::find($clashing))->toBeNull()
            ->and(RoleAssignment::find($clashingJefe))->toBeNull()
            ->and(Activity::query()->where('event', 'deleted')->count())->toBe(2)
            ->and($entry)
            ->subject_type->toBe(User::class)
            ->causer_id->toBeNull()
            ->event->toBe('deleted')
            ->description->toContain('Subdirección Académica')
            ->and($entry->properties->all())->toMatchArray([
                'role_assignment_id' => $clashing,
                'kept_role_assignment_id' => $kept,
                'role' => 'subdireccion',
                'campus_id' => $c2->id,
                'org_unit_id' => academicUnit(),
                'valid_from' => '2026-10-29',
                'valid_to' => null,
            ])
            ->and(Activity::query()->where('subject_id', $otherJefe->id)->sole()->properties['kept_role_assignment_id'])->toBe($keptJefe);
    });
});
