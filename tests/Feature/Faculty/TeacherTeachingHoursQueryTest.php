<?php

use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Faculty\Queries\TeacherTeachingHoursQuery;
use App\Modules\Staffing\Queries\TeachingTargetQuery;

/*
| PL-01: horas frente a grupo por docente, de sus plazas vigentes el primer día del periodo, sin N+1; y el objetivo de la
| selección (plazas en Nexus, o las horas del CSV si no tiene).
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['starts_on' => '2027-01-18', 'ends_on' => '2027-06-11']);
});

it('suma la tabla de cada plaza vigente al inicio del periodo y calla a quien no tiene plazas', function () {
    $a = Teacher::factory()->create();
    $b = Teacher::factory()->create();
    Teacher::factory()->create();
    TeacherPosition::factory()->for($a)->tiempoCompleto()->create(['category' => 'E3807']);
    TeacherPosition::factory()->for($a)->asignatura(6)->create();
    TeacherPosition::factory()->for($a)->medioTiempo()->create(['valid_from' => '2027-02-01']);
    TeacherPosition::factory()->for($b)->asignatura(10)->create();

    expect((new TeacherTeachingHoursQuery)->forTerm($this->term))->toBe([$a->id => 24 + 3, $b->id => 10]);
});

it('mide un grupo de docentes en una sola consulta de plazas', function () {
    $teachers = Teacher::factory()->count(3)->create();
    $teachers->each(fn (Teacher $teacher) => TeacherPosition::factory()->for($teacher)->tresCuartos()->create(['category' => 'E3759']));

    DB::enableQueryLog();
    (new TeacherTeachingHoursQuery)->forTerm($this->term, $teachers->pluck('id')->all());

    expect(DB::getQueryLog())->toHaveCount(1);
});

it('el objetivo de la selección sale de las plazas y, sin ellas, de las horas del CSV', function () {
    $withPositions = Teacher::factory()->create();
    $without = Teacher::factory()->create();
    TeacherPosition::factory()->for($withPositions)->tiempoCompleto()->create();

    $targets = app(TeachingTargetQuery::class)->forTerm($this->term, [$withPositions->id => 5, $without->id => 30]);

    expect($targets)->toBe([$withPositions->id => 22, $without->id => 16]);
});
