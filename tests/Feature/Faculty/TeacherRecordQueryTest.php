<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Faculty\Queries\TeacherRecordQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/*
| La ficha de un docente (TeacherRecordQuery): adscripciones con la principal primero, plazas con su estado, las
| horas base del periodo activo (S1 y S2, supuestos por confirmar: plazas vigentes el primer día, sin tope en la
| suma) y su usuario. Solo lectura.
*/

beforeEach(function () {
    $this->sc = Department::factory()->create(['code' => 'SC']);
    $this->ii = Department::factory()->create(['code' => 'II']);
    $this->term = Term::factory()->current()->create(['name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-25', 'ends_on' => '2027-06-11']);
    $this->user = User::factory()->create(['username' => '1000001']);
    $this->ana = Teacher::factory()->adscribedTo($this->ii)->create(['employee_number' => '1000001', 'user_id' => $this->user->id]);
    TeacherDepartment::factory()->for($this->ana)->for($this->sc)->create(['is_primary' => false]);
});

it('trae las adscripciones, sin campus, con la principal primero y su departamento', function () {
    $record = app(TeacherRecordQuery::class)->for($this->ana, $this->term);

    expect($record->adscriptions->pluck('is_primary')->all())->toBe([true, false])
        ->and($record->adscriptions->first()->department->code)->toBe('II');
});

it('S1 y S2: las horas base del periodo suman las plazas vigentes el primer día, sin tope', function () {
    TeacherPosition::factory()->for($this->ana)->tiempoCompleto()->create();
    TeacherPosition::factory()->for($this->ana)->asignatura(10)->create(['valid_from' => '2027-01-25', 'valid_to' => '2027-06-30']);
    TeacherPosition::factory()->for($this->ana)->asignatura(7)->create(['valid_from' => '2027-01-26']);
    TeacherPosition::factory()->for($this->ana)->expired()->create();

    $record = app(TeacherRecordQuery::class)->for($this->ana, $this->term);

    expect($record->baseHours)->toBe(50)
        ->and($record->term->name)->toBe('Ene-Jun 2027');
});

it('sin periodo activo no hay horas base', function () {
    TeacherPosition::factory()->for($this->ana)->tiempoCompleto()->create();

    expect(app(TeacherRecordQuery::class)->for($this->ana, null)->baseHours)->toBeNull();
});

it('marca cada plaza como vigente, por empezar o vencida, y si cuenta en el periodo', function () {
    $current = TeacherPosition::factory()->for($this->ana)->tiempoCompleto()->create();
    $future = TeacherPosition::factory()->for($this->ana)->future()->create();
    $expired = TeacherPosition::factory()->for($this->ana)->expired()->create();

    $record = app(TeacherRecordQuery::class)->for($this->ana, $this->term);

    expect($record->positionStatus)->toBe([$current->id => 'vigente', $future->id => 'por_empezar', $expired->id => 'vencida'])
        ->and($record->countsInTerm[$current->id])->toBeTrue()
        ->and($record->countsInTerm[$expired->id])->toBeFalse()
        ->and($record->positions->pluck('id')->all())->toBe([$current->id, $future->id, $expired->id]);
});

it('deja fuera las plazas y adscripciones quitadas', function () {
    TeacherPosition::factory()->for($this->ana)->tiempoCompleto()->create()->delete();
    $this->ana->adscriptions()->where('is_primary', false)->sole()->delete();

    $record = app(TeacherRecordQuery::class)->for($this->ana, $this->term);

    expect($record->positions)->toBeEmpty()
        ->and($record->adscriptions)->toHaveCount(1)
        ->and($record->baseHours)->toBe(0);
});

it('trae su usuario, o null si no tiene', function () {
    $without = Teacher::factory()->adscribedTo($this->sc)->create();

    expect(app(TeacherRecordQuery::class)->for($this->ana, $this->term)->user->username)->toBe('1000001')
        ->and(app(TeacherRecordQuery::class)->for($without, $this->term)->user)->toBeNull();
});

it('busca una adscripción o una plaza del docente por el id del navegador y no una de otro', function () {
    $query = app(TeacherRecordQuery::class);
    $position = TeacherPosition::factory()->for($this->ana)->create();
    $other = Teacher::factory()->adscribedTo($this->sc)->create();
    $otherAdscription = $other->adscriptions()->sole();

    expect($query->findAdscription($this->ana, $this->ana->adscriptions()->first()->id))->not->toBeNull()
        ->and($query->findPosition($this->ana, $position->id)->id)->toBe($position->id)
        ->and(fn () => $query->findAdscription($this->ana, $otherAdscription->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $query->findPosition($this->ana, 'no-es-uuid'))->toThrow(ModelNotFoundException::class);
});

it('lee con pocas consultas', function () {
    TeacherPosition::factory()->for($this->ana)->count(3)->create();
    DB::enableQueryLog();

    app(TeacherRecordQuery::class)->for($this->ana, $this->term);

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(6);
});
