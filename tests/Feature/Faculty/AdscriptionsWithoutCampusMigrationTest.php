<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Campus;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/*
| La migración que quita el campus de las adscripciones: la adscripción es solo a un departamento (confirmado el 30
| de octubre de 2026). Antes de quitar `campus_id` junta las de un docente en el mismo departamento y distinto campus:
| se queda una fila (la sin borrar, luego la principal, luego la más antigua) y las demás se eliminan con su entrada
| en la bitácora, porque la clave nueva, docente y departamento, cuenta también las borradas. La que se queda toma la
| fecha de alta más antigua de las vigentes de su grupo. Se prueba sobre la forma vieja: `down()`, filas con
| `DB::table()` y `up()`.
*/

beforeEach(function () {
    $this->migration = require base_path('app/Modules/Faculty/Database/Migrations/2026_10_30_000100_remove_campus_from_teacher_departments.php');
    $this->migration->down();

    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->sis = Department::factory()->create(['code' => 'SIS']);
    $this->ind = Department::factory()->create(['code' => 'IND']);
    $this->teacher = Teacher::factory()->create(['employee_number' => '1234567']);
});

/**
 * Inserta una adscripción con la forma vieja (con campus) y devuelve su id.
 */
function oldAdscription(Teacher $teacher, Department $department, Campus $campus, bool $primary, string $createdAt, ?string $deletedAt = null): string
{
    $id = (string) Str::uuid7();

    DB::table('teacher_departments')->insert([
        'id' => $id,
        'teacher_id' => $teacher->id,
        'department_id' => $department->id,
        'campus_id' => $campus->id,
        'is_primary' => $primary,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
        'deleted_at' => $deletedAt,
    ]);

    return $id;
}

/**
 * Los ids de las adscripciones que quedan, borradas o no.
 *
 * @return list<string>
 */
function remainingAdscriptions(): array
{
    return DB::table('teacher_departments')->orderBy('id')->pluck('id')->all();
}

it('quita el campus y junta en una las del mismo docente y departamento en dos campus, quedándose la principal', function () {
    $primary = oldAdscription($this->teacher, $this->sis, $this->c2, primary: true, createdAt: '2026-02-01');
    oldAdscription($this->teacher, $this->sis, $this->c1, primary: false, createdAt: '2026-01-01');
    $other = oldAdscription($this->teacher, $this->ind, $this->c1, primary: false, createdAt: '2026-03-01');

    $this->migration->up();

    expect(Schema::hasColumn('teacher_departments', 'campus_id'))->toBeFalse()
        ->and(remainingAdscriptions())->toEqualCanonicalizing([$primary, $other])
        ->and(TeacherDepartment::find($primary)->is_primary)->toBeTrue()
        ->and(TeacherDepartment::find($other)->is_primary)->toBeFalse();
});

it('la que se queda toma la fecha de alta más antigua de las vigentes de su grupo, no la de una borrada', function () {
    $c3 = Campus::factory()->create(['code' => 'C3']);
    $kept = oldAdscription($this->teacher, $this->sis, $this->c2, primary: true, createdAt: '2026-02-01');
    oldAdscription($this->teacher, $this->sis, $this->c1, primary: false, createdAt: '2026-01-01');
    oldAdscription($this->teacher, $this->sis, $c3, primary: false, createdAt: '2025-06-01', deletedAt: '2025-12-01');
    $alone = oldAdscription($this->teacher, $this->ind, $this->c1, primary: false, createdAt: '2026-03-01');

    $this->migration->up();

    expect(TeacherDepartment::find($kept)->created_at->toDateString())->toBe('2026-01-01')
        ->and(TeacherDepartment::find($alone)->created_at->toDateString())->toBe('2026-03-01');
});

it('sin principal en el departamento se queda la más antigua', function () {
    oldAdscription($this->teacher, $this->sis, $this->c1, primary: false, createdAt: '2026-03-01');
    $oldest = oldAdscription($this->teacher, $this->sis, $this->c2, primary: false, createdAt: '2026-01-01');

    $this->migration->up();

    expect(remainingAdscriptions())->toBe([$oldest]);
});

it('se queda la que no está borrada, aunque la borrada sea la principal o la más antigua', function () {
    oldAdscription($this->teacher, $this->sis, $this->c1, primary: true, createdAt: '2026-01-01', deletedAt: '2026-05-01');
    $current = oldAdscription($this->teacher, $this->sis, $this->c2, primary: false, createdAt: '2026-04-01');

    $this->migration->up();

    expect(remainingAdscriptions())->toBe([$current])
        ->and(TeacherDepartment::find($current))->not->toBeNull()
        ->and(TeacherDepartment::find($current)->is_primary)->toBeFalse();
});

it('si todas están borradas se queda la más antigua, borrada', function () {
    $oldest = oldAdscription($this->teacher, $this->sis, $this->c1, primary: false, createdAt: '2026-01-01', deletedAt: '2026-05-01');
    oldAdscription($this->teacher, $this->sis, $this->c2, primary: false, createdAt: '2026-02-01', deletedAt: '2026-05-01');

    $this->migration->up();

    expect(remainingAdscriptions())->toBe([$oldest])
        ->and(TeacherDepartment::withTrashed()->find($oldest)->trashed())->toBeTrue();
});

it('cada docente conserva una sola principal, y la base rechaza otra principal y repetir el departamento aunque esté borrado', function () {
    $other = Teacher::factory()->create();
    oldAdscription($this->teacher, $this->sis, $this->c1, primary: true, createdAt: '2026-01-01');
    oldAdscription($this->teacher, $this->sis, $this->c2, primary: false, createdAt: '2026-02-01');
    oldAdscription($this->teacher, $this->ind, $this->c2, primary: false, createdAt: '2026-03-01');
    oldAdscription($other, $this->ind, $this->c1, primary: false, createdAt: '2026-01-01', deletedAt: '2026-02-01');
    oldAdscription($other, $this->ind, $this->c2, primary: true, createdAt: '2026-03-01');

    $this->migration->up();

    $primaries = DB::table('teacher_departments')->whereNull('deleted_at')->where('is_primary', true)->selectRaw('teacher_id, count(*) as total')->groupBy('teacher_id')->pluck('total', 'teacher_id');

    expect($primaries->all())->toEqual([$this->teacher->id => 1, $other->id => 1])
        ->and(fn () => DB::transaction(fn () => TeacherDepartment::factory()->for($this->teacher)->create(['is_primary' => true])))->toThrow(UniqueConstraintViolationException::class);

    TeacherDepartment::query()->where('teacher_id', $this->teacher->id)->where('department_id', $this->ind->id)->sole()->delete();

    expect(fn () => TeacherDepartment::factory()->for($this->teacher)->for($this->ind)->create(['is_primary' => false]))->toThrow(UniqueConstraintViolationException::class);
});

it('deja en la bitácora cada fila que elimina, con lo que tenía y la que se queda', function () {
    $kept = oldAdscription($this->teacher, $this->sis, $this->c2, primary: true, createdAt: '2026-02-01');
    $gone = oldAdscription($this->teacher, $this->sis, $this->c1, primary: false, createdAt: '2026-01-01', deletedAt: '2026-03-01');

    $this->migration->up();

    $entry = Activity::sole();

    expect($entry->subject_type)->toBe(TeacherDepartment::class)
        ->and($entry->subject_id)->toBe($gone)
        ->and($entry->causer_id)->toBeNull()
        ->and($entry->event)->toBe('deleted')
        ->and($entry->description)->toBe('Juntó la adscripción del docente 1234567 a SIS en C1 con la que se queda, porque la adscripción ya no lleva campus')
        ->and($entry->attribute_changes->all())->toBe(['old' => [
            'department_id' => $this->sis->id,
            'campus_id' => $this->c1->id,
            'is_primary' => false,
            'created_at' => '2026-01-01 00:00:00-07',
            'deleted_at' => '2026-03-01 00:00:00-07',
        ]])
        ->and($entry->properties->all())->toBe(['teacher_id' => $this->teacher->id, 'kept_adscription_id' => $kept]);
});

it('sin repetidas no elimina nada ni escribe en la bitácora', function () {
    $sis = oldAdscription($this->teacher, $this->sis, $this->c1, primary: true, createdAt: '2026-01-01');
    $ind = oldAdscription($this->teacher, $this->ind, $this->c2, primary: false, createdAt: '2026-02-01');

    $this->migration->up();

    expect(remainingAdscriptions())->toEqualCanonicalizing([$sis, $ind])
        ->and(Activity::count())->toBe(0);
});
