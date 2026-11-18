<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Solver\Queries\BuildSolverInput;
use App\Modules\Solver\Queries\CommonCoreWarningQuery;
use App\Modules\Solver\Queries\RunOrderQuery;

/*
| Orden sugerido de corridas y aviso de tronco común (SV-20 y SV-21, tarea 6.9). Ciencias Básicas comparte cohortes con
| Sistemas y con Industrial; Sistemas e Industrial no comparten entre sí.
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['starts_on' => '2027-01-18']);
    $this->pres = modalityOf('PRES');
    $this->campus = Campus::factory()->create(['code' => 'S1']);

    $this->cb = Department::factory()->create(['name' => 'Ciencias Básicas']);
    $this->sc = Department::factory()->create(['name' => 'Sistemas']);
    $this->ii = Department::factory()->create(['name' => 'Industrial']);

    $careerSc = Career::factory()->create(['department_id' => $this->sc->id]);
    $careerIi = Career::factory()->create(['department_id' => $this->ii->id]);
    $planSc = StudyPlan::factory()->create(['career_id' => $careerSc->id]);
    $planIi = StudyPlan::factory()->create(['career_id' => $careerIi->id]);

    $this->cbCalcSc = orderGroup($this, $this->cb, 'AEF-1041', $careerSc, $planSc);
    $this->cbAlgSc = orderGroup($this, $this->cb, 'AEF-1042', $careerSc, $planSc);
    $this->cbFisIi = orderGroup($this, $this->cb, 'AEF-1043', $careerIi, $planIi);
    $this->scProg = orderGroup($this, $this->sc, 'SCC-1010', $careerSc, $planSc);
    $this->iiProc = orderGroup($this, $this->ii, 'INC-1010', $careerIi, $planIi);

    foreach ([$this->cb, $this->sc, $this->ii] as $department) {
        AcademicLoad::factory()->create(['term_id' => $this->term->id, 'modality_id' => $this->pres->id, 'department_id' => $department->id]);
    }
});

function orderGroup(object $test, Department $department, string $code, Career $career, StudyPlan $plan): Group
{
    $subject = Subject::factory()->create(['department_id' => $department->id, 'code' => $code, 'theory_hours' => 3, 'practice_hours' => 2]);

    return Group::factory()->create([
        'term_id' => $test->term->id,
        'campus_id' => $test->campus->id,
        'modality_id' => $test->pres->id,
        'subject_id' => $subject->id,
        'owner_career_id' => $career->id,
        'study_plan_id' => $plan->id,
        'semester' => 1,
        'capacity' => 30,
    ]);
}

function orderWarnings(object $test, Department $department): array
{
    return app(CommonCoreWarningQuery::class)->forDepartment($test->term, $test->pres, $department);
}

it('SV-20: avisa de la carga con la que se comparten cohortes y todavía no tiene horario, y deja de avisar cuando lo tiene', function () {
    $warnings = orderWarnings($this, $this->sc);

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['department']->id)->toBe($this->cb->id)
        ->and($warnings[0]['groups'])->toBe(2)
        ->and($warnings[0]['without_sessions'])->toBe(2);

    GroupSession::factory()->for($this->cbCalcSc)->on(1, '07:00', '09:00')->create();
    expect(orderWarnings($this, $this->sc)[0]['without_sessions'])->toBe(1);

    GroupSession::factory()->for($this->cbAlgSc)->on(2, '07:00', '09:00')->create();
    expect(orderWarnings($this, $this->sc))->toBe([]);
});

it('SV-20: no avisa de cargas que no comparten cohorte, y la de tronco común avisa de las dos que dependen de ella', function () {
    expect(collect(orderWarnings($this, $this->sc))->pluck('department.id')->all())->not->toContain($this->ii->id);

    $warnings = orderWarnings($this, $this->cb);

    expect(collect($warnings)->pluck('department.name')->all())->toBe(['Industrial', 'Sistemas'])
        ->and(collect($warnings)->pluck('without_sessions')->all())->toBe([1, 1]);
});

it('SV-21: sugiere primero la carga que más cohortes comparte con otras y, en empate, la de más grupos', function () {
    $order = app(RunOrderQuery::class)->forTerm($this->term, $this->pres);

    expect(collect($order)->pluck('department.name')->all())->toBe(['Ciencias Básicas', 'Industrial', 'Sistemas'])
        ->and($order[0]['shared_cohorts'])->toBe(2)
        ->and($order[0]['groups'])->toBe(3)
        ->and($order[0]['with_sessions'])->toBe(0)
        ->and($order[1]['shared_cohorts'])->toBe(1)
        ->and($order[0]['load_id'])->toBe(AcademicLoad::query()->where('department_id', $this->cb->id)->value('id'));

    GroupSession::factory()->for($this->cbCalcSc)->on(1, '07:00', '09:00')->create();

    expect(app(RunOrderQuery::class)->forTerm($this->term, $this->pres)[0]['with_sessions'])->toBe(1);
});

it('SV-20: dos corridas de departamento seguidas no se pisan: la segunda toma como fijas las sesiones de la primera', function () {
    GroupSession::factory()->for($this->cbCalcSc)->on(1, '07:00', '09:00')->create();

    $groups = collect(app(BuildSolverInput::class)->forDepartment($this->term, $this->pres, $this->sc, 'run-sc')->input->groups)->keyBy('id');

    expect($groups[$this->scProg->id]->movable)->toBeTrue()
        ->and($groups[$this->cbCalcSc->id]->movable)->toBeFalse()
        ->and($groups[$this->cbCalcSc->id]->fixedSessions)->toHaveCount(1)
        ->and($groups[$this->cbCalcSc->id]->fixedSessions[0]->day)->toBe(1)
        ->and($groups[$this->cbCalcSc->id]->currentSessions)->toBe([]);
});

it('SV-20: avisa del tronco común con varios grupos de la materia, que no llevan cohorte de HC-03, por el alumno tipo amplio', function () {
    $c2 = Campus::factory()->create(['code' => 'C2']);
    $career = Career::factory()->create(['department_id' => $this->sc->id]);
    $plan = StudyPlan::factory()->create(['career_id' => $career->id]);
    $calculo = Subject::factory()->create(['department_id' => $this->cb->id, 'code' => 'AEF-2001', 'theory_hours' => 3, 'practice_hours' => 2]);
    $fundamentos = Subject::factory()->create(['department_id' => $this->sc->id, 'code' => 'SCC-2001', 'theory_hours' => 3, 'practice_hours' => 2]);

    $make = fn (Subject $subject): Group => Group::factory()->create([
        'term_id' => $this->term->id, 'campus_id' => $c2->id, 'modality_id' => $this->pres->id, 'subject_id' => $subject->id,
        'owner_career_id' => $career->id, 'study_plan_id' => $plan->id, 'semester' => 1, 'capacity' => 30,
    ]);

    $calculoGroups = [$make($calculo), $make($calculo), $make($calculo)];
    $make($fundamentos);

    $warning = collect(orderWarnings($this, $this->sc))->firstWhere('department.id', $this->cb->id);

    expect($warning['groups'])->toBe(5)
        ->and($warning['without_sessions'])->toBe(5);

    foreach ($calculoGroups as $group) {
        GroupSession::factory()->for($group)->on(1, '07:00', '09:00')->create();
    }

    expect(orderWarnings($this, $this->sc)[0]['without_sessions'])->toBe(2);
});

it('SV-20: el cupo de otra carrera con la materia en su retícula también comparte alumno tipo amplio', function () {
    $c2 = Campus::factory()->create(['code' => 'C2']);
    $careerIi = Career::factory()->create(['department_id' => $this->ii->id]);
    $planIi = StudyPlan::factory()->create(['career_id' => $careerIi->id]);
    $other = Career::factory()->create(['department_id' => $this->cb->id]);
    $otherPlan = StudyPlan::factory()->create(['career_id' => $other->id]);
    $quimica = Subject::factory()->create(['department_id' => $this->cb->id, 'code' => 'AEF-3001', 'theory_hours' => 3, 'practice_hours' => 2]);
    $taller = Subject::factory()->create(['department_id' => $this->ii->id, 'code' => 'INC-3001', 'theory_hours' => 3, 'practice_hours' => 2]);
    StudyPlanSubject::factory()->create(['study_plan_id' => $planIi->id, 'subject_id' => $quimica->id, 'semester' => 2]);

    $base = ['term_id' => $this->term->id, 'campus_id' => $c2->id, 'modality_id' => $this->pres->id, 'semester' => 2, 'capacity' => 30];
    $cbGroup = Group::factory()->create([...$base, 'subject_id' => $quimica->id, 'owner_career_id' => $other->id, 'study_plan_id' => $otherPlan->id]);
    GroupQuota::factory()->create(['group_id' => $cbGroup->id, 'career_id' => $careerIi->id]);
    Group::factory()->create([...$base, 'subject_id' => $taller->id, 'owner_career_id' => $careerIi->id, 'study_plan_id' => $planIi->id]);

    $warning = collect(orderWarnings($this, $this->ii))->firstWhere('department.id', $this->cb->id);

    expect($warning['groups'])->toBe(2)
        ->and($warning['without_sessions'])->toBe(2);
});
