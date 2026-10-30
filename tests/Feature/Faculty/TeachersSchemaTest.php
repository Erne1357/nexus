<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Models\TeacherPosition;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Integridad de docentes, adscripciones y plazas (docs/dominio/modelo-de-datos.md, "Faculty"). Las claves
| naturales cuentan también los borrados; una sola adscripción principal sin borrar por docente; cada tipo
| de plaza acepta solo sus horas base (S3, supuesto por confirmar).
*/

describe('docentes', function () {
    it('el número de empleado es único, también contra un docente borrado', function () {
        Teacher::factory()->create(['employee_number' => '1234'])->delete();

        expect(fn () => Teacher::factory()->create(['employee_number' => '1234']))->toThrow(UniqueConstraintViolationException::class);
    });

    it('un usuario liga a un solo docente', function () {
        $user = User::factory()->create();
        Teacher::factory()->create(['user_id' => $user->id]);

        expect(fn () => Teacher::factory()->create(['user_id' => $user->id]))->toThrow(UniqueConstraintViolationException::class);
    });

    it('el grado solo acepta sus valores', function () {
        $row = Teacher::factory()->make()->attributesToArray();

        expect(fn () => DB::table('teachers')->insert([...$row, 'id' => (string) Str::uuid7(), 'degree' => 'bachiller']))->toThrow(QueryException::class, 'teachers_degree_check');
    });

    it('el grado puede quedar vacío', function () {
        expect(Teacher::factory()->create(['degree' => null])->fresh()->degree)->toBeNull();
    });
});

describe('adscripciones', function () {
    it('es única por docente, departamento y campus, también contra una borrada', function () {
        $teacher = Teacher::factory()->create();
        $department = Department::factory()->create();
        $campus = Campus::factory()->create();
        TeacherDepartment::factory()->for($teacher)->for($department)->for($campus)->create(['is_primary' => false])->delete();

        expect(fn () => TeacherDepartment::factory()->for($teacher)->for($department)->for($campus)->create(['is_primary' => false]))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('un docente puede estar en otro campus o en otro departamento', function () {
        $teacher = Teacher::factory()->create();
        $department = Department::factory()->create();
        $campus = Campus::factory()->create();
        TeacherDepartment::factory()->for($teacher)->for($department)->for($campus)->create(['is_primary' => true]);

        TeacherDepartment::factory()->for($teacher)->for($department)->for(Campus::factory())->create(['is_primary' => false]);
        TeacherDepartment::factory()->for($teacher)->for(Department::factory())->for($campus)->create(['is_primary' => false]);

        expect($teacher->adscriptions()->count())->toBe(3);
    });

    it('rechaza dos principales sin borrar del mismo docente', function () {
        $teacher = Teacher::factory()->create();
        TeacherDepartment::factory()->for($teacher)->create(['is_primary' => true]);

        expect(fn () => TeacherDepartment::factory()->for($teacher)->create(['is_primary' => true]))->toThrow(UniqueConstraintViolationException::class);
    });

    it('una principal borrada no cuenta', function () {
        $teacher = Teacher::factory()->create();
        TeacherDepartment::factory()->for($teacher)->create(['is_primary' => true])->delete();

        TeacherDepartment::factory()->for($teacher)->create(['is_primary' => true]);

        expect($teacher->adscriptions()->where('is_primary', true)->count())->toBe(1);
    });

    it('dos docentes pueden tener cada uno su principal', function () {
        TeacherDepartment::factory()->create(['is_primary' => true]);
        TeacherDepartment::factory()->create(['is_primary' => true]);

        expect(TeacherDepartment::count())->toBe(2);
    });

    it('necesita un departamento y un campus que existan', function () {
        $teacher = Teacher::factory()->create();

        expect(fn () => TeacherDepartment::factory()->for($teacher)->create(['department_id' => (string) Str::uuid7()]))
            ->toThrow(QueryException::class, 'foreign key');
    });
});

describe('plazas', function () {
    it('cada tipo acepta solo sus horas base (S3)', function (string $kind, int $hours, bool $valid) {
        $create = fn () => TeacherPosition::factory()->create(['kind' => $kind, 'base_hours' => $hours]);

        if ($valid) {
            expect($create()->fresh()->base_hours)->toBe($hours);
        } else {
            expect($create)->toThrow(QueryException::class, 'teacher_positions_hours_check');
        }
    })->with([
        'completo 40' => ['tiempo_completo', 40, true],
        'completo 39' => ['tiempo_completo', 39, false],
        'tres cuartos 30' => ['tres_cuartos', 30, true],
        'tres cuartos 40' => ['tres_cuartos', 40, false],
        'medio 20' => ['medio_tiempo', 20, true],
        'medio 10' => ['medio_tiempo', 10, false],
        'asignatura 1' => ['asignatura', 1, true],
        'asignatura 40' => ['asignatura', 40, true],
        'asignatura 0' => ['asignatura', 0, false],
        'asignatura 41' => ['asignatura', 41, false],
        'honorarios 0' => ['honorarios', 0, true],
        'honorarios 5' => ['honorarios', 5, false],
    ]);

    it('el tipo solo acepta sus valores', function () {
        $teacher = Teacher::factory()->create();

        expect(fn () => DB::table('teacher_positions')->insert(['teacher_id' => $teacher->id, 'kind' => 'interino', 'base_hours' => 10]))->toThrow(QueryException::class, 'violates check constraint');
    });

    it('rechaza una vigencia que termina antes de empezar', function () {
        expect(fn () => TeacherPosition::factory()->create(['valid_from' => '2027-02-01', 'valid_to' => '2027-01-31']))
            ->toThrow(QueryException::class, 'teacher_positions_validity_check');
    });

    it('acepta una vigencia de un solo día y los límites nulos', function () {
        TeacherPosition::factory()->create(['valid_from' => '2027-02-01', 'valid_to' => '2027-02-01']);
        TeacherPosition::factory()->create(['valid_from' => null, 'valid_to' => null]);

        expect(TeacherPosition::count())->toBe(2);
    });

    it('un docente puede tener varias plazas a la vez', function () {
        $teacher = Teacher::factory()->create();
        TeacherPosition::factory()->for($teacher)->count(3)->tiempoCompleto()->create();

        expect($teacher->positions()->count())->toBe(3);
    });
});
