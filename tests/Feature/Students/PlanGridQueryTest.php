<?php

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Enums\SubjectKind;
use App\Modules\Academic\Models\Specialty;
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

it('trae la especialidad de cada materia de especialidad, y las materias inactivas del catálogo', function () {
    $plan = StudyPlan::factory()->create();
    $specialty = Specialty::factory()->for($plan)->create();
    $common = Subject::factory()->inactive()->create();
    $network = Subject::factory()->create();
    placeInPlan($plan, $common);
    placeInPlan($plan, $network, 7, $specialty);

    $grid = app(PlanGridQuery::class)->forPlan($plan->id);

    expect(array_keys($grid->credits))->toBe([$common->id, $network->id])
        ->and($grid->specialtyOf)->toBe([$network->id => $specialty->id]);
});

it('trae el semestre de cada materia, el total del plan y los créditos que piden Servicio Social y Residencia', function () {
    $plan = StudyPlan::factory()->create(['total_credits' => 240]);
    $a = Subject::factory()->create(['code' => 'A-1']);
    $service = Subject::factory()->create(['code' => 'SS-9', 'kind' => SubjectKind::ServicioSocial]);
    $residency = Subject::factory()->create(['code' => 'RP-9', 'kind' => SubjectKind::Residencia]);
    placeInPlan($plan, $a);
    placeInPlan($plan, $service, 9);
    placeInPlan($plan, $residency, 9);
    seriate($plan, $residency, $a);

    $grid = app(PlanGridQuery::class)->forPlan($plan->id);

    // Residencia pide también el servicio social acreditado, además de su propia seriación.
    expect($grid->semesterOf)->toBe([$a->id => 1, $residency->id => 9, $service->id => 9])
        ->and($grid->creditShare)->toBe([$residency->id => 80, $service->id => 70])
        ->and($grid->seriation)->toBe([$residency->id => [$a->id, $service->id]])
        ->and($grid->totalCredits)->toBe(240);
});

it('un plan sin créditos totales capturados deja el total vacío', function () {
    $plan = StudyPlan::factory()->create(['total_credits' => null]);
    placeInPlan($plan, Subject::factory()->create());

    expect(app(PlanGridQuery::class)->forPlan($plan->id)->totalCredits)->toBeNull();
});
