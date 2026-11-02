<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherAvailability;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Models\TeacherExternalLoad;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Faculty\Models\TeacherSubjectQualification;
use App\Modules\Faculty\Queries\TeacherTermReadinessQuery;

/*
| Qué le falta a cada docente de un departamento en un periodo (disponibilidad S12, perfil S17, horas fuera del
| ITCJ S16): solo informa, no bloquea validar el catálogo (CV6).
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['starts_on' => '2027-01-18', 'ends_on' => '2027-06-11']);
    $this->other = Term::factory()->create(['starts_on' => '2026-08-17', 'ends_on' => '2026-12-11']);
    $this->sis = Department::factory()->create();
    $this->ele = Department::factory()->create();
    $this->query = new TeacherTermReadinessQuery(new App\Modules\Faculty\Queries\TeacherBaseHoursQuery);
});

function readinessTeacher(Department $department, array $attributes = []): Teacher
{
    $teacher = Teacher::factory()->create($attributes);
    TeacherDepartment::factory()->for($teacher)->for($department)->create();

    return $teacher;
}

it('trae a los docentes adscritos al departamento y a nadie más', function () {
    $mine = readinessTeacher($this->sis);
    readinessTeacher($this->ele);

    expect(array_keys($this->query->forDepartment($this->sis, $this->term)))->toBe([$mine->id]);
});

it('la disponibilidad de otro periodo o borrada no cuenta como capturada', function () {
    $with = readinessTeacher($this->sis);
    $otherTerm = readinessTeacher($this->sis);
    $deleted = readinessTeacher($this->sis);
    TeacherAvailability::factory()->for($with)->for($this->term)->create();
    TeacherAvailability::factory()->for($otherTerm)->for($this->other)->create();
    TeacherAvailability::factory()->for($deleted)->for($this->term)->create()->delete();

    $rows = $this->query->forDepartment($this->sis, $this->term);

    expect($rows[$with->id]['has_availability'])->toBeTrue()
        ->and($rows[$otherTerm->id]['has_availability'])->toBeFalse()
        ->and($rows[$deleted->id]['has_availability'])->toBeFalse();
});

it('el perfil cuenta aptos y preferentes solo en materias del departamento', function () {
    $teacher = readinessTeacher($this->sis);
    $none = readinessTeacher($this->sis);
    TeacherSubjectQualification::factory()->apto()->for($teacher)->for(Subject::factory()->create(['department_id' => $this->sis->id]))->create();
    TeacherSubjectQualification::factory()->preferente()->for($teacher)->for(Subject::factory()->create(['department_id' => $this->sis->id]))->create();
    TeacherSubjectQualification::factory()->apto()->for($teacher)->for(Subject::factory()->create(['department_id' => $this->ele->id]))->create();
    TeacherSubjectQualification::factory()->apto()->for($teacher)->for(Subject::factory()->create(['department_id' => $this->sis->id]))->create()->delete();
    TeacherSubjectQualification::factory()->apto()->for($none)->for(Subject::factory()->create(['department_id' => $this->ele->id]))->create();

    $rows = $this->query->forDepartment($this->sis, $this->term);

    expect($rows[$teacher->id]['subjects'])->toBe(2)
        ->and($rows[$none->id]['subjects'])->toBe(0);
});

it('las horas fuera del ITCJ son las del periodo y se suman', function () {
    $teacher = readinessTeacher($this->sis);
    TeacherExternalLoad::factory()->for($teacher)->for($this->term)->create(['hours' => 6]);
    TeacherExternalLoad::factory()->for($teacher)->for($this->term)->create(['hours' => 4]);
    TeacherExternalLoad::factory()->for($teacher)->for($this->other)->create(['hours' => 20]);

    expect($this->query->forDepartment($this->sis, $this->term)[$teacher->id]['external_hours'])->toBe(10);
});

it('trae las horas de plaza del periodo', function () {
    $teacher = readinessTeacher($this->sis);
    TeacherPosition::factory()->for($teacher)->tiempoCompleto()->create(['valid_from' => '2026-08-01']);

    expect($this->query->forDepartment($this->sis, $this->term)[$teacher->id]['base_hours'])->toBe(40);
});

it('cuenta los docentes activos sin disponibilidad y sin perfil, y da sus ids', function () {
    $ready = readinessTeacher($this->sis);
    $missingAll = readinessTeacher($this->sis);
    $inactive = readinessTeacher($this->sis, ['is_active' => false]);
    TeacherAvailability::factory()->for($ready)->for($this->term)->create();
    TeacherSubjectQualification::factory()->for($ready)->for(Subject::factory()->create(['department_id' => $this->sis->id]))->create();

    $rows = $this->query->forDepartment($this->sis, $this->term);

    expect($this->query->counts($rows))->toBe(['no_availability' => 1, 'no_profile' => 1])
        ->and($this->query->missingIds($rows, 'disponibilidad'))->toBe([$missingAll->id])
        ->and($this->query->missingIds($rows, 'perfil'))->toBe([$missingAll->id])
        ->and($rows[$inactive->id]['has_availability'])->toBeFalse();
});

it('hace una consulta por dato, no una por docente', function () {
    foreach (range(1, 6) as $i) {
        readinessTeacher($this->sis);
    }

    DB::enableQueryLog();
    $this->query->forDepartment($this->sis, $this->term);

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(6);
});
