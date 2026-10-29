<?php

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectPrerequisite;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Integridad de planes, materias, especialidades, retícula y seriación (tarea 1.7, docs/dominio/modelo-de-datos.md,
| "Academic"): las materias son globales y el tronco común se comparte entre carreras; la posición y la
| seriación son de cada plan (decisión de modelado 2). La base garantiza que la materia y su requisito están
| en la retícula del plan y que la especialidad de una materia es de su mismo plan. Las claves naturales son
| únicas también contra un registro borrado, y nada se borra físicamente (confirmado el 29 de octubre de 2026).
*/

describe('claves naturales', function () {
    it('la clave de un plan es única, también contra uno borrado', function () {
        StudyPlan::factory()->create(['code' => 'ISIC-2010-224'])->delete();

        expect(fn () => StudyPlan::factory()->create(['code' => 'ISIC-2010-224']))->toThrow(UniqueConstraintViolationException::class);
    });

    it('la clave de una materia es única, también contra una borrada', function () {
        Subject::factory()->create(['code' => 'ACF-0901'])->delete();

        expect(fn () => Subject::factory()->create(['code' => 'ACF-0901']))->toThrow(UniqueConstraintViolationException::class);
    });

    it('la clave de una especialidad es única dentro de su plan, también contra una borrada; otro plan puede repetirla', function () {
        $plan = StudyPlan::factory()->create();
        Specialty::factory()->for($plan)->create(['code' => 'ISIE-RED'])->delete();
        Specialty::factory()->for(StudyPlan::factory())->create(['code' => 'ISIE-RED']);

        expect(fn () => Specialty::factory()->for($plan)->create(['code' => 'ISIE-RED']))->toThrow(UniqueConstraintViolationException::class);
    });

    it('una materia aparece una sola vez en un plan, aunque se haya quitado', function () {
        $plan = StudyPlan::factory()->create();
        $subject = Subject::factory()->create();
        placeInPlan($plan, $subject)->delete();

        expect(fn () => placeInPlan($plan, $subject, 2))->toThrow(UniqueConstraintViolationException::class);
    });

    it('una seriación se registra una sola vez en un plan, aunque se haya quitado', function () {
        $plan = StudyPlan::factory()->create();
        [$calculo, $diferencial] = Subject::factory()->count(2)->create()->all();
        placeInPlan($plan, $calculo, 2);
        placeInPlan($plan, $diferencial, 1);
        seriate($plan, $calculo, $diferencial)->delete();

        expect(fn () => seriate($plan, $calculo, $diferencial, PrerequisiteKind::Correquisito))->toThrow(UniqueConstraintViolationException::class);
    });
});

describe('retícula y seriación por plan', function () {
    it('una materia del tronco común está en dos planes, cada uno con su semestre', function () {
        $comun = Subject::factory()->create(['code' => 'ACF-0901']);
        $sistemas = StudyPlan::factory()->create();
        $industrial = StudyPlan::factory()->create();

        placeInPlan($sistemas, $comun, 1);
        placeInPlan($industrial, $comun, 2);

        expect(StudyPlanSubject::where('subject_id', $comun->id)->orderBy('semester')->pluck('study_plan_id')->all())->toBe([$sistemas->id, $industrial->id]);
    });

    it('la misma materia se seria distinto en dos planes', function () {
        [$calculo, $diferencial, $algebra] = Subject::factory()->count(3)->create()->all();
        $sistemas = StudyPlan::factory()->create();
        $industrial = StudyPlan::factory()->create();
        foreach ([$sistemas, $industrial] as $plan) {
            placeInPlan($plan, $calculo, 2);
            placeInPlan($plan, $diferencial, 1);
            placeInPlan($plan, $algebra, 1);
        }

        seriate($sistemas, $calculo, $diferencial);
        seriate($industrial, $calculo, $algebra);

        expect(SubjectPrerequisite::where('study_plan_id', $sistemas->id)->sole()->prerequisite_subject_id)->toBe($diferencial->id)
            ->and(SubjectPrerequisite::where('study_plan_id', $industrial->id)->sole()->prerequisite_subject_id)->toBe($algebra->id);
    });

    it('la materia y su requisito tienen que estar en la retícula de ese plan', function (string $missing) {
        $plan = StudyPlan::factory()->create();
        [$calculo, $diferencial] = Subject::factory()->count(2)->create()->all();
        placeInPlan($plan, $missing === 'la materia' ? $diferencial : $calculo);
        // Está en la retícula de otro plan, no en la de este.
        placeInPlan(StudyPlan::factory()->create(), $missing === 'la materia' ? $calculo : $diferencial);

        expect(fn () => seriate($plan, $calculo, $diferencial))->toThrow(QueryException::class, 'foreign key');
    })->with(['la materia', 'el requisito']);

    it('una materia no es requisito de sí misma', function () {
        $plan = StudyPlan::factory()->create();
        $subject = Subject::factory()->create();
        placeInPlan($plan, $subject);

        expect(fn () => seriate($plan, $subject, $subject))->toThrow(QueryException::class, 'subject_prerequisites_not_itself_check');
    });

    it('la especialidad de una materia es de su mismo plan', function () {
        $plan = StudyPlan::factory()->create();
        $own = Specialty::factory()->for($plan)->create();
        $other = Specialty::factory()->for(StudyPlan::factory())->create();

        placeInPlan($plan, Subject::factory()->create(), 8, $own);

        expect(fn () => placeInPlan($plan, Subject::factory()->create(), 8, $other))->toThrow(QueryException::class, 'foreign key');
    });
});

describe('valores', function () {
    it('el semestre empieza en 1, y semestres, horas y créditos no son negativos', function (Closure $write, string $constraint) {
        expect($write)->toThrow(QueryException::class, $constraint);
    })->with([
        'semestre 0' => [fn () => StudyPlanSubject::factory()->create(['semester' => 0]), 'study_plan_subjects_semester_check'],
        'plan de 0 semestres' => [fn () => StudyPlan::factory()->create(['semesters_count' => 0]), 'study_plans_semesters_count_check'],
        'créditos de plan negativos' => [fn () => StudyPlan::factory()->create(['total_credits' => -1]), 'study_plans_total_credits_check'],
        'horas de teoría negativas' => [fn () => Subject::factory()->create(['theory_hours' => -1]), 'subjects_hours_check'],
        'horas de práctica negativas' => [fn () => Subject::factory()->create(['practice_hours' => -1]), 'subjects_hours_check'],
        'créditos negativos' => [fn () => Subject::factory()->create(['credits' => -1]), 'subjects_credits_check'],
    ]);

    it('solo acepta los tipos conocidos de materia y de seriación, aunque la escritura no pase por el modelo', function (string $table, string $constraint) {
        $placement = StudyPlanSubject::factory()->create();
        $prerequisite = placeInPlan($placement->studyPlan, Subject::factory()->create());
        $rows = [
            'subjects' => ['code' => 'X-1', 'name' => 'X', 'department_id' => $placement->subject->department_id, 'theory_hours' => 1, 'practice_hours' => 1, 'credits' => 2, 'kind' => 'basico'],
            'subject_prerequisites' => ['study_plan_id' => $placement->study_plan_id, 'subject_id' => $placement->subject_id, 'prerequisite_subject_id' => $prerequisite->subject_id, 'kind' => 'Seriación'],
        ];

        expect(fn () => DB::table($table)->insert($rows[$table]))->toThrow(QueryException::class, $constraint);
    })->with([
        'materia' => ['subjects', 'subjects_kind_check'],
        'seriación' => ['subject_prerequisites', 'subject_prerequisites_kind_check'],
    ]);

    it('guarda una materia sin tipo y un plan sin semestres, créditos ni fecha de inicio: la exportación del SII no los trae', function () {
        $subject = Subject::factory()->create(['kind' => null]);
        $plan = StudyPlan::factory()->create(['semesters_count' => null, 'total_credits' => null, 'valid_from' => null]);

        expect($subject->fresh()->kind)->toBeNull()
            ->and($plan->fresh()->only(['semesters_count', 'total_credits', 'valid_from']))->toBe(['semesters_count' => null, 'total_credits' => null, 'valid_from' => null]);
    });

    it('un plan necesita su carrera y una materia, su departamento', function (Closure $write) {
        expect($write)->toThrow(QueryException::class, 'foreign key');
    })->with([
        'plan' => [fn () => StudyPlan::factory()->create(['career_id' => (string) Str::uuid7()])],
        'materia' => [fn () => Subject::factory()->create(['department_id' => (string) Str::uuid7()])],
    ]);
});
