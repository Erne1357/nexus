<?php

use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherAvailability;
use App\Modules\Faculty\Models\TeacherEvaluation;
use App\Modules\Faculty\Models\TeacherExternalLoad;
use App\Modules\Faculty\Models\TeacherSubjectHistory;
use App\Modules\Faculty\Models\TeacherSubjectQualification;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Integridad de disponibilidad, horas externas, historial, perfil y evaluaciones (docs/dominio/modelo-de-datos.md,
| "Faculty"). La disponibilidad no se traslapa por docente, periodo y día; las claves naturales cuentan también
| los borrados. Escala de evaluación y una fila por grupo: confirmados el 30 de octubre de 2026; S16, supuesto.
*/

function availabilityBlock(Teacher $teacher, Term $term, string $from, string $to, int $day = 1): TeacherAvailability
{
    return TeacherAvailability::factory()->for($teacher)->for($term)->create(['day' => $day, 'starts_at' => $from, 'ends_at' => $to]);
}

/**
 * Inserta una fila con un valor que el cast del enum no deja pasar, para probar el CHECK de la base.
 *
 * @param  array<string, mixed>  $override
 */
function insertRawRecord(string $table, array $row, array $override): void
{
    DB::table($table)->insert([...$row, 'id' => (string) Str::uuid7(), ...$override]);
}

describe('disponibilidad', function () {
    it('rechaza dos bloques que se traslapan del mismo docente, periodo y día', function () {
        $teacher = Teacher::factory()->create();
        $term = Term::factory()->create();
        availabilityBlock($teacher, $term, '09:00', '11:00');

        expect(fn () => availabilityBlock($teacher, $term, '10:00', '12:00'))->toThrow(QueryException::class, 'teacher_availability_no_overlap');
    });

    it('rechaza un bloque contenido en otro aunque sea de otro tipo', function () {
        $teacher = Teacher::factory()->create();
        $term = Term::factory()->create();
        availabilityBlock($teacher, $term, '09:00', '12:00');

        expect(fn () => TeacherAvailability::factory()->preferred()->for($teacher)->for($term)->create(['starts_at' => '10:00', 'ends_at' => '11:00']))
            ->toThrow(QueryException::class, 'teacher_availability_no_overlap');
    });

    it('acepta bloques contiguos', function () {
        $teacher = Teacher::factory()->create();
        $term = Term::factory()->create();
        availabilityBlock($teacher, $term, '09:00', '10:00');
        availabilityBlock($teacher, $term, '10:00', '11:00');

        expect($teacher->availability()->count())->toBe(2);
    });

    it('acepta el mismo horario en otro periodo, otro día u otro docente', function () {
        $teacher = Teacher::factory()->create();
        $term = Term::factory()->create();
        availabilityBlock($teacher, $term, '09:00', '11:00');
        availabilityBlock($teacher, Term::factory()->create(), '09:00', '11:00');
        availabilityBlock($teacher, $term, '09:00', '11:00', day: 2);
        availabilityBlock(Teacher::factory()->create(), $term, '09:00', '11:00');

        expect(TeacherAvailability::count())->toBe(4);
    });

    it('un bloque borrado no cuenta para el traslape', function () {
        $teacher = Teacher::factory()->create();
        $term = Term::factory()->create();
        availabilityBlock($teacher, $term, '09:00', '11:00')->delete();

        availabilityBlock($teacher, $term, '10:00', '12:00');

        expect($teacher->availability()->count())->toBe(1);
    });

    it('el día va de 1 a 6', function (int $day) {
        expect(fn () => TeacherAvailability::factory()->create(['day' => $day]))->toThrow(QueryException::class, 'teacher_availability_day_check');
    })->with([0, 7]);

    it('el sábado es un día válido', function () {
        expect(TeacherAvailability::factory()->create(['day' => 6])->day)->toBe(6);
    });

    it('la hora final es posterior a la inicial', function (string $from, string $to) {
        expect(fn () => TeacherAvailability::factory()->create(['starts_at' => $from, 'ends_at' => $to]))->toThrow(QueryException::class, 'teacher_availability_time_check');
    })->with([['10:00', '10:00'], ['11:00', '10:00']]);

    it('el tipo solo acepta sus valores', function () {
        expect(fn () => insertRawRecord('teacher_availability', TeacherAvailability::factory()->make()->attributesToArray(), ['kind' => 'maybe']))->toThrow(QueryException::class, 'teacher_availability_kind_check');
    });
});

describe('horas externas', function () {
    it('van de 1 a 40 (S16)', function (int $hours) {
        expect(fn () => TeacherExternalLoad::factory()->create(['hours' => $hours]))->toThrow(QueryException::class, 'teacher_external_loads_hours_check');
    })->with([0, 41]);

    it('acepta 1 y 40 horas (S16)', function (int $hours) {
        expect(TeacherExternalLoad::factory()->create(['hours' => $hours])->hours)->toBe($hours);
    })->with([1, 40]);

    it('es única por docente, periodo y origen, también contra una borrada (S16)', function () {
        $teacher = Teacher::factory()->create();
        $term = Term::factory()->create();
        TeacherExternalLoad::factory()->for($teacher)->for($term)->create(['source' => 'TecNM Virtual'])->delete();

        expect(fn () => TeacherExternalLoad::factory()->for($teacher)->for($term)->create(['source' => 'TecNM Virtual']))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('otro origen o periodo del mismo docente es otra fila (S16)', function () {
        $teacher = Teacher::factory()->create();
        $term = Term::factory()->create();
        TeacherExternalLoad::factory()->for($teacher)->for($term)->create(['source' => 'TecNM Virtual']);
        TeacherExternalLoad::factory()->for($teacher)->for($term)->create(['source' => 'Otra escuela']);
        TeacherExternalLoad::factory()->for($teacher)->create(['source' => 'TecNM Virtual']);

        expect($teacher->externalLoads()->count())->toBe(3);
    });
});

describe('historial', function () {
    it('dos grupos de la misma materia y periodo son dos filas (una fila por grupo)', function () {
        $teacher = Teacher::factory()->create();
        $subject = Subject::factory()->create();
        $campus = Campus::factory()->create();
        TeacherSubjectHistory::factory()->for($teacher)->for($subject)->for($campus)->create(['term_code' => '20241', 'group_code' => 'A']);
        TeacherSubjectHistory::factory()->for($teacher)->for($subject)->for($campus)->create(['term_code' => '20241', 'group_code' => 'B']);

        expect($teacher->history()->count())->toBe(2);
    });

    it('el mismo grupo se repite solo una vez, también contra una fila borrada', function () {
        $teacher = Teacher::factory()->create();
        $subject = Subject::factory()->create();
        $campus = Campus::factory()->create();
        $key = ['term_code' => '20241', 'group_code' => 'A'];
        TeacherSubjectHistory::factory()->for($teacher)->for($subject)->for($campus)->create($key)->delete();

        expect(fn () => TeacherSubjectHistory::factory()->for($teacher)->for($subject)->for($campus)->create($key))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('sin campus la clave también es única', function () {
        $teacher = Teacher::factory()->create();
        $subject = Subject::factory()->create();
        $key = ['campus_id' => null, 'term_code' => '20241', 'group_code' => 'A'];
        TeacherSubjectHistory::factory()->for($teacher)->for($subject)->create($key);

        expect(fn () => TeacherSubjectHistory::factory()->for($teacher)->for($subject)->create($key))->toThrow(UniqueConstraintViolationException::class);
    });

    it('el mismo grupo en otro campus o periodo es otra fila', function () {
        $teacher = Teacher::factory()->create();
        $subject = Subject::factory()->create();
        $campus = Campus::factory()->create();
        TeacherSubjectHistory::factory()->for($teacher)->for($subject)->for($campus)->create(['term_code' => '20241', 'group_code' => 'A']);
        TeacherSubjectHistory::factory()->for($teacher)->for($subject)->create(['term_code' => '20241', 'group_code' => 'A']);
        TeacherSubjectHistory::factory()->for($teacher)->for($subject)->for($campus)->create(['term_code' => '20243', 'group_code' => 'A']);

        expect($teacher->history()->count())->toBe(3);
    });

    it('el código de periodo sigue el formato del kárdex', function (string $code) {
        expect(fn () => TeacherSubjectHistory::factory()->create(['term_code' => $code]))->toThrow(QueryException::class, 'teacher_subject_history_term_code_check');
    })->with(['2024', '20244', '2024-1', 'abcd1']);

    it('el origen solo acepta sus valores', function () {
        expect(fn () => insertRawRecord('teacher_subject_history', TeacherSubjectHistory::factory()->make()->attributesToArray(), ['source' => 'excel']))->toThrow(QueryException::class, 'teacher_subject_history_source_check');
    });
});

describe('perfil', function () {
    it('es único por docente y materia, también contra uno borrado', function () {
        $teacher = Teacher::factory()->create();
        $subject = Subject::factory()->create();
        TeacherSubjectQualification::factory()->for($teacher)->for($subject)->create()->delete();

        expect(fn () => TeacherSubjectQualification::factory()->for($teacher)->for($subject)->preferente()->create())
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('el nivel solo acepta sus valores', function () {
        expect(fn () => insertRawRecord('teacher_subject_qualifications', TeacherSubjectQualification::factory()->make()->attributesToArray(), ['level' => 'experto']))->toThrow(QueryException::class, 'teacher_subject_qualifications_level_check');
    });
});

describe('evaluaciones', function () {
    it('el puntaje va de 0 a 5 y 5.01 se rechaza', function (float $score) {
        expect(fn () => TeacherEvaluation::factory()->create(['score' => $score]))->toThrow(QueryException::class, 'teacher_evaluations_score_check');
    })->with([5.01, 6, -0.5]);

    it('acepta los extremos de la escala', function (float $score) {
        expect((float) TeacherEvaluation::factory()->create(['score' => $score])->score)->toBe($score);
    })->with([0.0, 1.0, 5.0]);

    it('es única por docente, periodo y tipo, también contra una borrada', function () {
        $teacher = Teacher::factory()->create();
        TeacherEvaluation::factory()->for($teacher)->estudiantil()->create(['term_code' => '20241'])->delete();

        expect(fn () => TeacherEvaluation::factory()->for($teacher)->estudiantil()->create(['term_code' => '20241']))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('el otro tipo o periodo es otra fila', function () {
        $teacher = Teacher::factory()->create();
        TeacherEvaluation::factory()->for($teacher)->estudiantil()->create(['term_code' => '20241']);
        TeacherEvaluation::factory()->for($teacher)->departamental()->create(['term_code' => '20241']);
        TeacherEvaluation::factory()->for($teacher)->estudiantil()->create(['term_code' => '20243']);

        expect($teacher->evaluations()->count())->toBe(3);
    });

    it('el código de periodo sigue el formato del kárdex', function () {
        expect(fn () => TeacherEvaluation::factory()->create(['term_code' => '2024']))->toThrow(QueryException::class, 'teacher_evaluations_term_code_check');
    });

    it('el tipo solo acepta sus valores', function () {
        expect(fn () => insertRawRecord('teacher_evaluations', TeacherEvaluation::factory()->make()->attributesToArray(), ['kind' => 'otro']))->toThrow(QueryException::class, 'teacher_evaluations_kind_check');
    });
});
