<?php

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Students\Queries\PlanGridQuery;

/*
| PlanGridQuery arma la retícula de un plan para calcular el avance (tarea 1.4): sus materias con sus créditos
| en orden de semestre y su seriación, sin lo que se quitó de la retícula y sin correquisitos.
*/

it('arma la retícula en orden de semestre y clave, con los créditos y solo la seriación', function () {
    $plan = StudyPlan::factory()->create();
    $a = Subject::factory()->create(['code' => 'A-1', 'credits' => 5]);
    $f = Subject::factory()->create(['code' => 'F-1', 'credits' => 4]);
    $b = Subject::factory()->create(['code' => 'B-2', 'credits' => 5]);
    placeInPlan($plan, $b, 2);
    placeInPlan($plan, $f, 1);
    placeInPlan($plan, $a, 1);
    seriate($plan, $b, $a);
    seriate($plan, $b, $f, PrerequisiteKind::Correquisito);

    $grid = app(PlanGridQuery::class)->forPlan($plan->id);

    expect($grid->credits)->toBe([$a->id => 5, $f->id => 4, $b->id => 5])
        ->and($grid->seriation)->toBe([$b->id => [$a->id]]);
});

it('deja fuera las materias y seriaciones que se quitaron, y las materias borradas del catálogo', function () {
    $plan = StudyPlan::factory()->create();
    [$a, $b, $removed, $deleted] = Subject::factory()->count(4)->create()->all();
    placeInPlan($plan, $a);
    placeInPlan($plan, $b, 2);
    placeInPlan($plan, $removed)->delete();
    placeInPlan($plan, $deleted);
    $deleted->delete();
    seriate($plan, $b, $a)->delete();

    $grid = app(PlanGridQuery::class)->forPlan($plan->id);

    expect(array_keys($grid->credits))->toEqualCanonicalizing([$a->id, $b->id])
        ->and($grid->seriation)->toBe([]);
});
