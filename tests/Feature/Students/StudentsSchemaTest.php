<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Core\Models\Term;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Models\StudentProgress;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Integridad de alumnos, kardex y progreso (tarea 1.4, docs/dominio/modelo-de-datos.md, "Students"). El
| número de control es la clave natural (reglas-de-negocio.md, "Reglas de datos"); el kardex es único por
| alumno, materia y periodo, y el periodo lleva el código del SII (`20271` Ene-Jun, `20272` verano, `20273`
| Ago-Dic, confirmado el 29 de octubre de 2026). Nada se borra físicamente.
*/

describe('alumnos', function () {
    it('el número de control es único, también contra un alumno borrado', function () {
        Student::factory()->create(['control_number' => '19111234'])->delete();

        expect(fn () => Student::factory()->create(['control_number' => '19111234']))->toThrow(UniqueConstraintViolationException::class);
    });

    it('un alumno necesita carrera, plan y campus que existan', function (string $column) {
        expect(fn () => Student::factory()->create([$column => (string) Str::uuid7()]))->toThrow(QueryException::class, 'foreign key');
    })->with(['career_id', 'study_plan_id', 'campus_id']);

    it('el plan de un alumno es de su carrera', function () {
        $student = Student::factory()->create();

        expect(fn () => $student->update(['career_id' => Career::factory()->create()->id]))->toThrow(QueryException::class, 'foreign key');
    });

    it('si el plan cambia de carrera, sus alumnos cambian con él', function () {
        $student = Student::factory()->create();
        $career = Career::factory()->create();

        $student->studyPlan->update(['career_id' => $career->id]);

        expect($student->fresh()->career_id)->toBe($career->id);
    });

    it('acepta cada estatus del enum: si se agrega uno, también va en la restricción de la base', function (StudentStatus $status) {
        expect(Student::factory()->create(['status' => $status])->fresh()->status)->toBe($status);
    })->with(StudentStatus::cases());

    it('el semestre actual empieza en 1 y puede faltar, como en un egresado', function () {
        Student::factory()->create(['current_semester' => null]);

        expect(fn () => Student::factory()->create(['current_semester' => 0]))->toThrow(QueryException::class, 'students_current_semester_check');
    });

    it('el estatus solo acepta los conocidos, o queda vacío si el SII trae otro', function () {
        Student::factory()->create(['status' => null]);

        expect(fn () => DB::table('students')->insert([...Student::factory()->raw(), 'status' => 'Activo']))->toThrow(QueryException::class, 'students_status_check');
    });
});

describe('kardex', function () {
    it('un alumno tiene una sola fila por materia y periodo, también contra una borrada', function () {
        $record = KardexRecord::factory()->create(['term_code' => '20253']);
        $record->delete();

        expect(fn () => KardexRecord::factory()->for($record->student)->for($record->subject)->create(['term_code' => '20253']))->toThrow(UniqueConstraintViolationException::class);
    });

    it('la misma materia en otro periodo, como al repetirla, es otra fila', function () {
        $record = KardexRecord::factory()->failed()->create(['term_code' => '20251']);
        KardexRecord::factory()->for($record->student)->for($record->subject)->create(['term_code' => '20253', 'attempt' => 2]);

        expect($record->student->kardexRecords()->count())->toBe(2);
    });

    it('el periodo tiene el código del SII: año y 1, 2 o 3, sin guión', function (string $term) {
        expect(fn () => KardexRecord::factory()->create(['term_code' => $term]))->toThrow(QueryException::class, 'kardex_records_term_code_check');
    })->with(['2025-1', '20254', '2025', '202531']);

    it('acepta los tres periodos del año, también el verano', function (string $term) {
        expect(KardexRecord::factory()->create(['term_code' => $term])->term_code)->toBe($term);
    })->with(['20251', '20252', '20253']);

    it('la oportunidad empieza en 1, la calificación va de 0 a 100 y el resultado solo acepta los conocidos', function (array $attributes, string $constraint) {
        expect(fn () => DB::table('kardex_records')->insert([...KardexRecord::factory()->raw(), ...$attributes]))->toThrow(QueryException::class, $constraint);
    })->with([
        'oportunidad 0' => [['attempt' => 0], 'kardex_records_attempt_check'],
        'calificación negativa' => [['grade' => -1], 'kardex_records_grade_check'],
        'calificación arriba de 100' => [['grade' => 100.5], 'kardex_records_grade_check'],
        'resultado desconocido' => [['result' => 'AC'], 'kardex_records_result_check'],
    ]);

    it('acepta cada resultado del enum: si se agrega uno, también va en la restricción de la base', function (KardexResult $result) {
        expect(KardexRecord::factory()->create(['result' => $result])->fresh()->result)->toBe($result);
    })->with(KardexResult::cases());

    it('una fila de kardex necesita un alumno y una materia que existan', function (string $column) {
        expect(fn () => KardexRecord::factory()->create([$column => (string) Str::uuid7()]))->toThrow(QueryException::class, 'foreign key');
    })->with(['student_id', 'subject_id']);
});

describe('periodos', function () {
    it('un periodo de Nexus lleva el código del SII: año y 1, 2 o 3, sin guión', function () {
        Term::factory()->create(['code' => '20272']);

        expect(fn () => Term::factory()->create(['code' => '2027-1']))->toThrow(QueryException::class, 'terms_code_check');
    });
});

describe('progreso', function () {
    it('un alumno tiene un solo progreso', function () {
        $progress = StudentProgress::factory()->create();

        expect(fn () => StudentProgress::factory()->for($progress->student)->create())->toThrow(UniqueConstraintViolationException::class);
    });

    it('las cuentas de créditos y materias no son negativas', function (string $column, string $constraint) {
        expect(fn () => StudentProgress::factory()->create([$column => -1]))->toThrow(QueryException::class, $constraint);
    })->with([
        ['credits_approved', 'student_progress_credits_approved_check'],
        ['subjects_approved', 'student_progress_subjects_approved_check'],
    ]);
});
