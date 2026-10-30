<?php

use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Faculty\Queries\TeacherBaseHoursQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
| Horas base por docente: S1 (vigentes el primer día del periodo) y S2 (suma sin tope), supuestos por
| confirmar, en una sola consulta agregada.
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['starts_on' => '2027-01-18', 'ends_on' => '2027-06-11']);
});

it('forTerm cuenta las plazas vigentes el primer día del periodo y las suma', function () {
    $teacher = Teacher::factory()->create();
    TeacherPosition::factory()->for($teacher)->tiempoCompleto()->create(['valid_from' => '2026-08-01', 'valid_to' => null]);
    TeacherPosition::factory()->for($teacher)->asignatura(10)->create(['valid_from' => '2027-01-18', 'valid_to' => '2027-06-11']);
    TeacherPosition::factory()->for($teacher)->medioTiempo()->create(['valid_from' => '2027-01-19']);
    TeacherPosition::factory()->for($teacher)->medioTiempo()->create(['valid_from' => '2025-01-01', 'valid_to' => '2027-01-17']);

    expect((new TeacherBaseHoursQuery)->forTerm($this->term))->toBe([$teacher->id => 50]);
});

it('una plaza borrada no cuenta y tres de tiempo completo suman 120', function () {
    $teacher = Teacher::factory()->create();
    TeacherPosition::factory()->for($teacher)->tiempoCompleto()->count(3)->create(['valid_from' => null]);
    TeacherPosition::factory()->for($teacher)->tiempoCompleto()->create()->delete();

    expect((new TeacherBaseHoursQuery)->forTerm($this->term)[$teacher->id])->toBe(120);
});

it('un docente sin plazas da 0', function () {
    $teacher = Teacher::factory()->create();

    expect((new TeacherBaseHoursQuery)->forTerm($this->term))->toBe([$teacher->id => 0]);
});

it('filtra por ids', function () {
    [$a, $b, $c] = Teacher::factory()->count(3)->create();
    TeacherPosition::factory()->for($a)->tiempoCompleto()->create();
    TeacherPosition::factory()->for($b)->medioTiempo()->create();

    expect((new TeacherBaseHoursQuery)->forTerm($this->term, [$a->id, $c->id]))->toBe([$a->id => 40, $c->id => 0]);
});

it('un docente borrado no aparece', function () {
    Teacher::factory()->create()->delete();

    expect((new TeacherBaseHoursQuery)->forTerm($this->term))->toBe([]);
});

it('onDate usa el día de Ciudad Juárez', function () {
    $teacher = Teacher::factory()->create();
    TeacherPosition::factory()->for($teacher)->medioTiempo()->create(['valid_to' => '2027-01-18']);

    expect((new TeacherBaseHoursQuery)->onDate(Carbon::parse('2027-01-19 03:00', 'UTC')))->toBe([$teacher->id => 20])
        ->and((new TeacherBaseHoursQuery)->onDate(Carbon::parse('2027-01-19 12:00', 'UTC')))->toBe([$teacher->id => 0]);
});

it('resuelve con muchos docentes en una sola consulta', function () {
    $teachers = Teacher::factory()->count(5)->create();
    $teachers->each(fn (Teacher $teacher) => TeacherPosition::factory()->for($teacher)->count(2)->medioTiempo()->create());

    DB::enableQueryLog();
    $hours = (new TeacherBaseHoursQuery)->forTerm($this->term);
    $queries = DB::getRawQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(1)
        ->and($hours)->toHaveCount(5)
        ->and(array_values(array_unique($hours)))->toBe([40]);
});
