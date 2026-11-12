<?php

use App\Modules\Core\Enums\Modality as CampusModality;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| La migración que agrega la modalidad a los nombramientos (AZ-S17; M5 y M9, confirmados el 12 de noviembre de 2026).
| El coordinador pide modalidad y carrera; el jefe de proyecto docente, modalidad y departamento; el campus es opcional
| en los dos. Los coordinadores y jefes de proyecto docente que ya existen reciben Presencial y conservan su campus, así
| que su alcance de hoy no cambia; si alguno tiene un campus que no es presencial, se detiene, lista los ids y no deja
| nada. Se prueba sobre la forma vieja: `down()`, filas con `DB::table()` y `up()`.
*/

beforeEach(function () {
    $this->migration = require base_path('app/Modules/Core/Database/Migrations/2026_11_12_210201_add_modality_to_role_assignments.php');
    $this->migration->down();

    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
});

/**
 * Un nombramiento con `DB::table()` y la forma de antes de la modalidad.
 *
 * @param  array<string, mixed>  $scope
 */
function assignmentBeforeModality(Role $role, array $scope, string $from = '2026-08-01', ?string $to = null): string
{
    return DB::table('role_assignments')->insertGetId([
        'user_id' => User::factory()->create()->id,
        'role' => $role->value,
        'valid_from' => $from,
        'valid_to' => $to,
        'created_at' => now(),
        'updated_at' => now(),
        ...$scope,
    ]);
}

/**
 * Un nombramiento con `DB::table()` después de la migración.
 *
 * @param  array<string, mixed>  $scope
 */
function modalityAssignmentRow(Role $role, array $scope, ?string $userId = null, string $from = '2026-08-01', ?string $to = null): string
{
    $userId ??= User::factory()->create()->id;

    // En su propio punto de guardado: si la base lo rechaza, la transacción de la prueba sigue sirviendo.
    return DB::transaction(fn (): string => DB::table('role_assignments')->insertGetId([
        'user_id' => $userId,
        'role' => $role->value,
        'valid_from' => $from,
        'valid_to' => $to,
        ...$scope,
    ]));
}

it('AZ-S17: el coordinador de C2 queda en Presencial y conserva C2; el jefe de proyecto docente de C1, en Presencial y C1', function () {
    $coordinator = assignmentBeforeModality(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)]);
    $lead = assignmentBeforeModality(Role::JefeProyectoDocente, ['campus_id' => $this->c1->id, 'org_unit_id' => departmentKey(10)]);
    $ended = assignmentBeforeModality(Role::Coordinador, ['campus_id' => $this->c1->id, 'career_id' => careerKey(21)], '2025-01-01', '2025-12-31');

    $this->migration->up();

    $rows = DB::table('role_assignments')->whereIn('id', [$coordinator, $lead, $ended])->get()->keyBy('id');

    expect($rows[$coordinator])->modality_id->toBe(modalityOf('PRES')->id)->campus_id->toBe($this->c2->id)
        ->and($rows[$lead])->modality_id->toBe(modalityOf('PRES')->id)->campus_id->toBe($this->c1->id)
        ->and($rows[$ended])->modality_id->toBe(modalityOf('PRES')->id)->campus_id->toBe($this->c1->id);
});

it('los puestos sin modalidad la dejan vacía: jefe de departamento, Subdirección, docente y admin', function () {
    $ids = [
        assignmentBeforeModality(Role::JefeDepartamento, ['org_unit_id' => departmentKey(10)]),
        assignmentBeforeModality(Role::Docente, ['campus_id' => $this->c2->id]),
        assignmentBeforeModality(Role::Admin, []),
    ];

    $this->migration->up();

    expect(DB::table('role_assignments')->whereIn('id', $ids)->pluck('modality_id')->unique()->all())->toBe([null]);
});

it('AZ-S17: la base rechaza un coordinador o un jefe de proyecto docente sin modalidad', function (Role $role) {
    $this->migration->up();
    $scope = $role === Role::Coordinador ? ['career_id' => careerKey(20)] : ['org_unit_id' => departmentKey(10)];

    expect(fn () => modalityAssignmentRow($role, ['campus_id' => $this->c2->id, ...$scope]))->toThrow(QueryException::class, 'role_assignments_scope_complete');
})->with([
    'coordinador' => [Role::Coordinador],
    'jefe de proyecto docente' => [Role::JefeProyectoDocente],
]);

it('AZ-S17: el campus es opcional: la base acepta un coordinador y un jefe de proyecto docente de una modalidad sin campus', function () {
    $this->migration->up();

    modalityAssignmentRow(Role::Coordinador, ['modality_id' => modalityOf('PRES')->id, 'career_id' => careerKey(20)]);
    modalityAssignmentRow(Role::JefeProyectoDocente, ['modality_id' => modalityOf('PRES')->id, 'org_unit_id' => departmentKey(10)]);

    expect(DB::table('role_assignments')->whereNull('campus_id')->count())->toBe(2);
});

it('la base sigue rechazando al coordinador sin carrera y al jefe de proyecto docente sin departamento', function () {
    $this->migration->up();

    expect(fn () => modalityAssignmentRow(Role::Coordinador, ['modality_id' => modalityOf('PRES')->id, 'campus_id' => $this->c2->id]))
        ->toThrow(QueryException::class, 'role_assignments_scope_complete')
        ->and(fn () => modalityAssignmentRow(Role::JefeProyectoDocente, ['modality_id' => modalityOf('PRES')->id, 'campus_id' => $this->c2->id]))
        ->toThrow(QueryException::class, 'role_assignments_scope_complete');
});

it('la misma persona no es dos veces jefe de proyecto docente del mismo departamento y modalidad sin campus en fechas que se traslapan', function () {
    $this->migration->up();
    $user = User::factory()->create()->id;
    $scope = ['modality_id' => modalityOf('PRES')->id, 'org_unit_id' => departmentKey(10)];

    modalityAssignmentRow(Role::JefeProyectoDocente, $scope, $user, '2026-08-01', '2026-12-31');

    expect(fn () => modalityAssignmentRow(Role::JefeProyectoDocente, $scope, $user, '2026-12-01'))
        ->toThrow(QueryException::class, 'role_assignments_jpd_no_duplicate');
});

it('la exclusión del jefe de proyecto docente distingue modalidad y campus, y sigue admitiendo a varias personas', function () {
    $this->migration->up();
    modalityOf('EAD')->update(['is_active' => true]);
    $user = User::factory()->create()->id;
    $systems = departmentKey(10);
    $pres = modalityOf('PRES')->id;

    modalityAssignmentRow(Role::JefeProyectoDocente, ['modality_id' => $pres, 'org_unit_id' => $systems], $user);
    modalityAssignmentRow(Role::JefeProyectoDocente, ['modality_id' => $pres, 'org_unit_id' => $systems, 'campus_id' => $this->c2->id], $user);
    modalityAssignmentRow(Role::JefeProyectoDocente, ['modality_id' => modalityOf('EAD')->id, 'org_unit_id' => $systems], $user);
    modalityAssignmentRow(Role::JefeProyectoDocente, ['modality_id' => $pres, 'org_unit_id' => $systems]);

    expect(fn () => modalityAssignmentRow(Role::JefeProyectoDocente, ['modality_id' => $pres, 'org_unit_id' => $systems, 'campus_id' => $this->c2->id], $user, '2026-09-01'))
        ->toThrow(QueryException::class, 'role_assignments_jpd_no_duplicate')
        ->and(DB::table('role_assignments')->where('role', Role::JefeProyectoDocente->value)->count())->toBe(4);
});

it('la modalidad de un nombramiento apunta al catálogo', function () {
    $this->migration->up();

    expect(fn () => modalityAssignmentRow(Role::Coordinador, ['modality_id' => fakeKey(999_999), 'career_id' => careerKey(20)]))
        ->toThrow(QueryException::class, 'foreign key');
});

it('el admin sigue sin alcance: tampoco lleva modalidad', function () {
    $this->migration->up();

    expect(fn () => modalityAssignmentRow(Role::Admin, ['modality_id' => modalityOf('PRES')->id]))
        ->toThrow(QueryException::class, 'role_assignments_admin_unscoped');
});

it('se detiene, lista los ids y no deja nada si un coordinador o un jefe de proyecto docente tiene un campus que no es presencial', function () {
    $ead = Campus::factory()->create(['code' => 'EAD', 'modality' => CampusModality::Mixta, 'is_active' => false]);
    $fine = assignmentBeforeModality(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)]);
    $coordinator = assignmentBeforeModality(Role::Coordinador, ['campus_id' => $ead->id, 'career_id' => careerKey(21)]);
    $lead = assignmentBeforeModality(Role::JefeProyectoDocente, ['campus_id' => $ead->id, 'org_unit_id' => departmentKey(10)]);
    $before = DB::table('role_assignments')->orderBy('id')->get()->all();

    expect(fn () => $this->migration->up())->toThrow(function (RuntimeException $exception) use ($coordinator, $lead, $fine): void {
        expect($exception->getMessage())->toContain($coordinator)->toContain($lead)->not->toContain($fine);
    });

    expect(Schema::hasColumn('role_assignments', 'modality_id'))->toBeFalse()
        ->and(DB::table('role_assignments')->orderBy('id')->get()->all())->toEqual($before);
});

it('down() quita la modalidad y vuelve a pedir el campus al coordinador y al jefe de proyecto docente', function () {
    $this->migration->up();
    $this->migration->down();

    expect(Schema::hasColumn('role_assignments', 'modality_id'))->toBeFalse()
        ->and(fn () => modalityAssignmentRow(Role::Coordinador, ['career_id' => careerKey(20)]))
        ->toThrow(QueryException::class, 'role_assignments_scope_complete');
});
