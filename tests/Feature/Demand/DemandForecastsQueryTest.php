<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Demand\Queries\DemandForecastsQuery;

/*
| Lectura de la proyección para la propuesta del coordinador (5.5 y 5.6, por carrera) y el tablero del departamento
| (5.7, por las materias del departamento, de todas las carreras y campus). Siempre de un periodo y una modalidad.
*/

beforeEach(function () {
    $this->term = Term::factory()->create();
    $this->modality = modalityOf('PRES');
    $this->career = Career::factory()->create();
    $this->c1 = Campus::factory()->create(['code' => 'X1']);
    $this->c2 = Campus::factory()->create(['code' => 'X2']);
});

function forecastFor(Term $term, Career $career, Subject $subject, ?Campus $campus, array $attributes = []): DemandForecast
{
    return DemandForecast::factory()->create([
        'term_id' => $term->id,
        'modality_id' => modalityOf('PRES')->id,
        'career_id' => $career->id,
        'subject_id' => $subject->id,
        'campus_id' => $campus?->id,
        ...$attributes,
    ]);
}

it('da la proyección de una carrera por materia y, dentro, por campus', function () {
    $calculus = Subject::factory()->create();
    $physics = Subject::factory()->create();
    forecastFor($this->term, $this->career, $calculus, $this->c1, ['expected_students' => 70]);
    forecastFor($this->term, $this->career, $calculus, $this->c2, ['expected_students' => 30]);
    forecastFor($this->term, $this->career, $physics, $this->c1, ['expected_students' => 12]);

    $rows = app(DemandForecastsQuery::class)->forCareer($this->term, $this->modality, $this->career);

    expect($rows->keys()->all())->toEqualCanonicalizing([$calculus->id, $physics->id])
        ->and($rows[$calculus->id]->keys()->all())->toEqualCanonicalizing([$this->c1->id, $this->c2->id])
        ->and($rows[$calculus->id][$this->c1->id]->expected_students)->toBe(70)
        ->and($rows[$calculus->id][$this->c2->id]->expected_students)->toBe(30)
        ->and($rows[$physics->id][$this->c1->id]->expected_students)->toBe(12);
});

it('la fila sin campus de una modalidad sin salones queda con la llave vacía', function () {
    $subject = Subject::factory()->create();
    forecastFor($this->term, $this->career, $subject, null, ['expected_students' => 9]);

    $rows = app(DemandForecastsQuery::class)->forCareer($this->term, $this->modality, $this->career);

    expect($rows[$subject->id]['']->expected_students)->toBe(9);
});

it('no mezcla otro periodo, otra modalidad ni otra carrera', function () {
    $subject = Subject::factory()->create();
    forecastFor($this->term, $this->career, $subject, $this->c1);
    forecastFor(Term::factory()->create(), $this->career, $subject, $this->c1);
    forecastFor($this->term, Career::factory()->create(), $subject, $this->c1);
    DemandForecast::factory()->create([
        'term_id' => $this->term->id,
        'modality_id' => modalityOf('EAD')->id,
        'career_id' => $this->career->id,
        'subject_id' => $subject->id,
        'campus_id' => $this->c1->id,
    ]);

    $rows = app(DemandForecastsQuery::class)->forCareer($this->term, $this->modality, $this->career);

    expect($rows)->toHaveCount(1)
        ->and($rows[$subject->id])->toHaveCount(1);
});

it('da al departamento sus materias con las filas de todas las carreras y campus', function () {
    $department = Department::factory()->create();
    $own = Subject::factory()->create(['department_id' => $department->id]);
    $alien = Subject::factory()->create();
    $other = Career::factory()->create();
    forecastFor($this->term, $this->career, $own, $this->c1, ['expected_students' => 40]);
    forecastFor($this->term, $this->career, $own, $this->c2, ['expected_students' => 20]);
    forecastFor($this->term, $other, $own, $this->c1, ['expected_students' => 15]);
    forecastFor($this->term, $this->career, $alien, $this->c1);

    $rows = app(DemandForecastsQuery::class)->forDepartment($this->term, $this->modality, $department);

    expect($rows->keys()->all())->toBe([$own->id])
        ->and($rows[$own->id])->toHaveCount(3)
        ->and($rows[$own->id]->sum('expected_students'))->toBe(75)
        ->and($rows[$own->id]->every(fn (DemandForecast $forecast): bool => $forecast->relationLoaded('career') && $forecast->relationLoaded('campus')))->toBeTrue();
});
