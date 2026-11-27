<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Queries\StudentAveragesQuery;

/*
| Los promedios del alumno (AC-24, tarea 1.67): general (materias acreditadas), con reprobadas (todas las oportunidades
| calificadas) y del último periodo con calificaciones; dos decimales, desde el kárdex, sin guardarse.
*/

beforeEach(function () {
    $career = Career::factory()->for(Department::factory()->create())->create();
    $plan = StudyPlan::factory()->for($career)->create();
    $this->student = Student::factory()->for($plan)->create(['career_id' => $career->id, 'campus_id' => Campus::factory()->create()->id]);
    $this->subjects = Subject::factory()->count(4)->create();
    $this->average = fn (): array => (array) app(StudentAveragesQuery::class)->forStudent($this->student);
    $this->row = fn (int $subject, ?string $term, ?float $grade, KardexResult $result = KardexResult::Aprobada, int $attempt = 1) => KardexRecord::factory()->for($this->student)->for($this->subjects[$subject])->create(['term_code' => $term, 'grade' => $grade, 'result' => $result, 'attempt' => $attempt]);
});

it('AC-24: el promedio general es el de las calificaciones de las materias acreditadas, con dos decimales', function () {
    ($this->row)(0, '20243', 80);
    ($this->row)(1, '20251', 91);
    ($this->row)(2, '20251', 85.5);
    // Una reprobada que luego aprobó: cuenta la que la acreditó, no el 40 de la primera oportunidad.
    ($this->row)(3, '20243', 40, KardexResult::Reprobada);
    ($this->row)(3, '20251', 70, KardexResult::Aprobada, 2);

    expect(($this->average)()['general'])->toBe('81.63');
});

it('AC-24: el promedio con reprobadas cuenta todas las oportunidades calificadas, aprobadas y reprobadas', function () {
    ($this->row)(0, '20243', 80);
    ($this->row)(1, '20243', 40, KardexResult::Reprobada);
    ($this->row)(1, '20251', 70, KardexResult::Aprobada, 2);

    expect(($this->average)()['withFailed'])->toBe('63.33');
});

it('AC-24: el promedio del último periodo es el de los renglones calificados del periodo más reciente con calificaciones', function () {
    ($this->row)(0, '20243', 80);
    ($this->row)(1, '20251', 90);
    ($this->row)(2, '20251', 71, KardexResult::Reprobada);
    // Cursando el periodo en curso: sin calificación, no cuenta aunque sea el más reciente.
    ($this->row)(3, '20253', null, KardexResult::Cursando);

    $averages = ($this->average)();

    expect($averages['lastTerm'])->toBe('80.50')->and($averages['lastTermCode'])->toBe('20251');
});

it('AC-24: ignora lo que no tiene calificación numérica y da null cuando no hay con qué promediar', function () {
    expect(($this->average)())->toBe(['general' => null, 'withFailed' => null, 'lastTerm' => null, 'lastTermCode' => null]);

    // Acreditada por convalidación, sin nota.
    ($this->row)(0, '20243', null);
    ($this->row)(1, null, 90);

    $averages = ($this->average)();

    expect($averages['general'])->toBe('90.00')->and($averages['withFailed'])->toBe('90.00')
        ->and($averages['lastTerm'])->toBeNull();
});

it('AC-24: no mezcla el kárdex de otro alumno', function () {
    ($this->row)(0, '20243', 80);
    KardexRecord::factory()->for(Student::factory()->create())->for($this->subjects[1])->create(['term_code' => '20243', 'grade' => 100, 'result' => KardexResult::Aprobada]);

    expect(($this->average)()['general'])->toBe('80.00');
});
