<?php

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Enums\SubjectKind;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectPrerequisite;

/*
| Relaciones de planes, materias, especialidades, retícula y seriación (tarea 1.7): lo que leerán la retícula
| del coordinador (fase 2), el cálculo de progreso (1.4) y los elegibles (1.5).
*/

it('un plan pertenece a su carrera, y la carrera lista sus planes', function () {
    $career = Career::factory()->create();
    $plan = StudyPlan::factory()->for($career)->create();

    expect($plan->career->is($career))->toBeTrue()
        ->and($career->studyPlans->modelKeys())->toBe([$plan->id]);
});

it('un plan lista sus materias con semestre, especialidad y si es obligatoria, sin las que se quitaron', function () {
    $plan = StudyPlan::factory()->create();
    $specialty = Specialty::factory()->for($plan)->create();
    $first = Subject::factory()->create(['code' => 'A1']);
    $elective = Subject::factory()->create(['code' => 'B8']);
    StudyPlanSubject::factory()->for($plan)->for($first)->create(['semester' => 1]);
    StudyPlanSubject::factory()->for($plan)->for($elective)->create(['semester' => 8, 'specialty_id' => $specialty->id, 'is_required' => false]);
    StudyPlanSubject::factory()->for($plan)->for(Subject::factory()->create(['code' => 'C3']))->create()->delete();

    $subjects = $plan->subjects()->orderBy('code')->get();

    expect($subjects->pluck('code')->all())->toBe(['A1', 'B8'])
        ->and($subjects->map(fn (Subject $subject): array => [$subject->pivot->semester, $subject->pivot->specialty_id, $subject->pivot->is_required])->all())
        ->toBe([[1, null, true], [8, $specialty->id, false]])
        ->and($plan->placements()->count())->toBe(2)
        ->and($plan->specialties->modelKeys())->toBe([$specialty->id]);
});

it('una materia da su departamento y los planes donde aparece; el departamento lista sus materias', function () {
    $department = Department::factory()->create();
    $subject = Subject::factory()->for($department)->create(['kind' => SubjectKind::Basica]);
    $plans = StudyPlan::factory()->count(2)->create();
    $plans->each(fn (StudyPlan $plan) => StudyPlanSubject::factory()->for($plan)->for($subject)->create());

    expect($subject->department->is($department))->toBeTrue()
        ->and($department->subjects->modelKeys())->toBe([$subject->id])
        ->and($subject->studyPlans->modelKeys())->toEqualCanonicalizing($plans->modelKeys())
        ->and($subject->kind)->toBe(SubjectKind::Basica);
});

it('una materia de la retícula da su plan, su materia y su especialidad', function () {
    $placement = StudyPlanSubject::factory()->create(['specialty_id' => null]);

    expect($placement->studyPlan)->toBeInstanceOf(StudyPlan::class)
        ->and($placement->subject)->toBeInstanceOf(Subject::class)
        ->and($placement->specialty)->toBeNull();
});

it('una seriación da su plan, su materia y su requisito; el plan lista sus seriaciones', function () {
    $prerequisite = SubjectPrerequisite::factory()->create(['kind' => PrerequisiteKind::Correquisito]);

    expect($prerequisite->studyPlan->prerequisites->modelKeys())->toBe([$prerequisite->id])
        ->and($prerequisite->subject->id)->toBe($prerequisite->subject_id)
        ->and($prerequisite->prerequisite->id)->toBe($prerequisite->prerequisite_subject_id)
        ->and($prerequisite->kind)->toBe(PrerequisiteKind::Correquisito);
});
