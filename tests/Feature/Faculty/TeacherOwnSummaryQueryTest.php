<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Enums\AvailabilityKind;
use App\Modules\Faculty\Enums\TeacherDegree;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherAvailability;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Faculty\Models\TeacherSubjectQualification;
use App\Modules\Faculty\Queries\TeacherOwnSummaryQuery;

/*
| TeacherOwnSummaryQuery (1.85, IN-01 e IN-03): la ficha, la experiencia académica y la disponibilidad del propio docente
| para su Resumen, solo de lectura y solo de ese docente.
*/

beforeEach(function () {
    $this->query = app(TeacherOwnSummaryQuery::class);
    $this->teacher = Teacher::factory()->create(['name' => 'Laura Pérez', 'degree' => TeacherDegree::Maestria, 'hired_on' => '2015-08-17']);
});

describe('experiencia', function () {
    it('S17: las preferentes primero, luego por nombre, con el total', function () {
        $subject = fn (string $code, string $name) => Subject::factory()->create(['code' => $code, 'name' => $name]);
        TeacherSubjectQualification::factory()->for($this->teacher)->for($subject('B1', 'Cálculo'))->apto()->create();
        TeacherSubjectQualification::factory()->for($this->teacher)->for($subject('B2', 'Álgebra'))->apto()->create();
        TeacherSubjectQualification::factory()->for($this->teacher)->for($subject('B3', 'Redes'))->preferente()->create();
        TeacherSubjectQualification::factory()->for($this->teacher)->for($subject('B4', 'Bases de Datos'))->preferente()->create();

        $experience = $this->query->experience($this->teacher);

        expect($experience['total'])->toBe(4)
            ->and(array_column($experience['subjects'], 'name'))->toBe(['Bases de Datos', 'Redes', 'Álgebra', 'Cálculo'])
            ->and($experience['subjects'][0])->toMatchArray(['code' => 'B4', 'level' => 'Preferente']);
    });

    it('no cuenta las quitadas ni las de otro docente', function () {
        $kept = TeacherSubjectQualification::factory()->for($this->teacher)->create();
        TeacherSubjectQualification::factory()->for($this->teacher)->create()->delete();
        TeacherSubjectQualification::factory()->create();

        $experience = $this->query->experience($this->teacher);

        expect($experience['total'])->toBe(1)
            ->and($experience['subjects'][0]['subject_id'])->toBe($kept->subject_id);
    });

    it('sin experiencia da cero y lista vacía', function () {
        expect($this->query->experience($this->teacher))->toBe(['total' => 0, 'subjects' => []]);
    });
});

describe('disponibilidad', function () {
    beforeEach(function () {
        $this->term = Term::factory()->create(['code' => '20271', 'starts_on' => '2027-01-25', 'ends_on' => '2027-05-28']);
    });

    it('da los rangos por día como texto con su tipo y deja fuera los días sin rangos', function () {
        TeacherAvailability::factory()->for($this->teacher)->for($this->term)->create(['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'kind' => AvailabilityKind::Unavailable]);
        TeacherAvailability::factory()->for($this->teacher)->for($this->term)->create(['day' => 1, 'starts_at' => '13:00', 'ends_at' => '15:00', 'kind' => AvailabilityKind::Preferred]);
        TeacherAvailability::factory()->for($this->teacher)->for($this->term)->create(['day' => 3, 'starts_at' => '10:00', 'ends_at' => '12:00', 'kind' => AvailabilityKind::Unavailable]);

        $days = $this->query->availability($this->teacher, $this->term);

        expect(array_column($days, 'day'))->toBe([1, 3])
            ->and(array_column($days[0]['ranges'], 'label'))->toBe(['7:00–9:00', '13:00–15:00'])
            ->and($days[0]['ranges'][0])->toMatchArray(['kind' => 'unavailable', 'kind_label' => AvailabilityKind::Unavailable->label()])
            ->and($days[1]['ranges'][0]['label'])->toBe('10:00–12:00');
    });

    it('sin nada guardado en el periodo está vacía; lo de otro periodo y otro docente no cuenta', function () {
        TeacherAvailability::factory()->for($this->teacher)->create(['day' => 1]);
        TeacherAvailability::factory()->for(Teacher::factory())->for($this->term)->create(['day' => 2]);

        expect($this->query->availability($this->teacher, $this->term))->toBe([]);
    });
});

describe('identidad', function () {
    it('da la ficha: principal más otros, plazas vigentes hoy, grado e ingreso', function () {
        $main = Department::factory()->create(['code' => 'DSC', 'name' => 'Sistemas y Computación']);
        $other = Department::factory()->create(['code' => 'ZZZ', 'name' => 'Otro']);
        $teacher = Teacher::factory()->adscribedTo($other, false)->adscribedTo($main)
            ->create(['name' => 'Laura Pérez', 'degree' => TeacherDegree::Maestria, 'hired_on' => '2015-08-17']);
        TeacherPosition::factory()->for($teacher)->tiempoCompleto()->create();
        TeacherPosition::factory()->for($teacher)->expired()->create();
        TeacherPosition::factory()->for($teacher)->future()->create();

        $identity = $this->query->identity($teacher);

        expect($identity)->toMatchArray([
            'name' => 'Laura Pérez',
            'active' => true,
            'department' => ['code' => 'DSC', 'name' => 'Sistemas y Computación'],
            'other_departments' => 1,
            'degree' => 'Maestría',
        ])
            ->and($identity['positions'])->toBe([['kind' => 'Tiempo completo', 'base_hours' => 40]])
            ->and($identity['hired_on']->toDateString())->toBe('2015-08-17');
    });

    it('sin adscripciones, plazas, grado ni ingreso devuelve vacíos', function () {
        $identity = $this->query->identity(Teacher::factory()->inactive()->create(['degree' => null, 'hired_on' => null]));

        expect($identity)->toMatchArray(['active' => false, 'department' => null, 'other_departments' => 0, 'positions' => [], 'degree' => null, 'hired_on' => null]);
    });
});
