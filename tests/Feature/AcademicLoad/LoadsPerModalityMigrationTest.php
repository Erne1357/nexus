<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| Contraer la carga a una por departamento y modalidad (M1, confirmado el 12 de noviembre de 2026; MS12, supuesto por
| confirmar): por periodo, modalidad y departamento, las cargas de varios campus se funden en una. Se queda la que tiene
| historia (transiciones o snapshots) o, si ninguna la tiene, la más antigua; las demás, sin historia, se borran con su
| entrada en la bitácora. Si dos o más tienen historia, se detiene con los ids y no cambia nada (la historia es
| inmutable, HC-18). Después la llave única es (periodo, modalidad, departamento) y `academic_loads.campus_id` se quita.
| Se prueba sobre la forma vieja: `down()`, filas con `DB::table()` y `up()`.
*/

beforeEach(function () {
    (require base_path('app/Modules/Core/Database/Migrations/2026_11_12_211102_contract_campuses_to_places.php'))->down();
    $this->migration = require base_path('app/Modules/AcademicLoad/Database/Migrations/2026_11_12_210501_make_academic_loads_per_modality.php');
    $this->migration->down();
    $this->term = Term::factory()->create();
    $this->c1 = campusBeforeContraction('C1');
    $this->c2 = campusBeforeContraction('C2');
    $this->presencial = Modality::query()->where('code', 'PRES')->sole();
    $this->sis = Department::factory()->create(['code' => 'SIS']);
    $this->user = User::factory()->create();
});

/**
 * Inserta una carga con la forma de antes (con campus) y devuelve su id. `$createdAt` ordena cuál es la más antigua.
 */
function loadBeforePerModality(object $test, Campus $campus, string $createdAt, string $status = 'draft'): string
{
    $id = (string) Str::uuid7();

    DB::table('academic_loads')->insert([
        'id' => $id,
        'term_id' => $test->term->id,
        'campus_id' => $campus->id,
        'modality_id' => $test->presencial->id,
        'department_id' => $test->sis->id,
        'status' => $status,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    return $id;
}

function transitionBeforePerModality(object $test, string $loadId): void
{
    DB::table('load_transitions')->insert([
        'academic_load_id' => $loadId,
        'from_status' => 'draft',
        'to_status' => 'proposed',
        'user_id' => $test->user->id,
        'created_at' => now(),
    ]);
}

it('funde en una las dos cargas en borrador de C1 y C2 del mismo departamento, periodo y modalidad (M1)', function () {
    $older = loadBeforePerModality($this, $this->c2, '2026-11-01 10:00:00');
    $newer = loadBeforePerModality($this, $this->c1, '2026-11-02 10:00:00');

    $this->migration->up();

    expect(DB::table('academic_loads')->pluck('id')->all())->toBe([$older])
        ->and(Schema::hasColumn('academic_loads', 'campus_id'))->toBeFalse()
        ->and(DB::table('activity_log')->where('subject_id', $newer)->where('event', 'deleted')->count())->toBe(1);
});

it('se queda con la carga que tiene transiciones aunque la otra sea más antigua (MS12)', function () {
    $withoutHistory = loadBeforePerModality($this, $this->c1, '2026-11-01 10:00:00');
    $withHistory = loadBeforePerModality($this, $this->c2, '2026-11-02 10:00:00', 'proposed');
    transitionBeforePerModality($this, $withHistory);

    $this->migration->up();

    expect(DB::table('academic_loads')->pluck('id')->all())->toBe([$withHistory])
        ->and(DB::table('activity_log')->where('subject_id', $withoutHistory)->where('event', 'deleted')->value('properties'))
        ->toContain($withHistory);
});

it('se detiene con los ids si dos cargas tienen historia y no cambia nada (MS12, HC-18)', function () {
    $c1 = loadBeforePerModality($this, $this->c1, '2026-11-01 10:00:00', 'proposed');
    $c2 = loadBeforePerModality($this, $this->c2, '2026-11-02 10:00:00', 'proposed');
    transitionBeforePerModality($this, $c1);
    transitionBeforePerModality($this, $c2);

    expect(fn () => $this->migration->up())->toThrow(RuntimeException::class, $c1)
        ->and(fn () => $this->migration->up())->toThrow(RuntimeException::class, $c2);

    expect(DB::table('academic_loads')->orderBy('id')->pluck('id')->all())->toBe(collect([$c1, $c2])->sort()->values()->all())
        ->and(Schema::hasColumn('academic_loads', 'campus_id'))->toBeTrue();
});

it('no funde cargas de departamentos o periodos distintos, y después rechaza una segunda carga del mismo departamento y modalidad', function () {
    $other = Department::factory()->create(['code' => 'CB']);
    $sis = loadBeforePerModality($this, $this->c1, '2026-11-01 10:00:00');
    DB::table('academic_loads')->insert([
        'id' => $cb = (string) Str::uuid7(),
        'term_id' => $this->term->id,
        'campus_id' => $this->c2->id,
        'modality_id' => $this->presencial->id,
        'department_id' => $other->id,
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->migration->up();

    expect(DB::table('academic_loads')->pluck('id')->sort()->values()->all())->toBe(collect([$sis, $cb])->sort()->values()->all())
        ->and(fn () => DB::table('academic_loads')->insert([
            'term_id' => $this->term->id,
            'modality_id' => $this->presencial->id,
            'department_id' => $this->sis->id,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(UniqueConstraintViolationException::class);
});
