<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Queries\PlanSemestersQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/*
| PlanSemestersQuery (tarea 3.5): la navegación del editor, carrera → retícula → semestre (confirmado el 7 de
| noviembre de 2026). Las carreras son las que se ofrecen en el campus y el actor ve con «Ver los horarios»: el
| coordinador la suya en su campus, el jefe las de su departamento y, como supuesto por confirmar, las carreras donde
| su departamento tiene grupos en el periodo y campus; el admin y la Subdirección, todas. Cada retícula y semestre
| cuenta sus grupos y los que tienen horas sin acomodar.
*/

beforeEach(function () {
    $this->term = Term::factory()->create();
    $this->campus = Campus::factory()->create();
    $this->otherCampus = Campus::factory()->create();
    $this->sistemas = Department::factory()->create();
    $this->basicas = Department::factory()->create();
    $this->isc = Career::factory()->create(['code' => 'ISC', 'department_id' => $this->sistemas->id]);
    $this->iind = Career::factory()->create(['code' => 'IIND']);
    CareerCampus::factory()->create(['career_id' => $this->isc->id, 'campus_id' => $this->campus->id]);
    CareerCampus::factory()->create(['career_id' => $this->iind->id, 'campus_id' => $this->campus->id]);
    CareerCampus::factory()->create(['career_id' => Career::factory()->create(['code' => 'IGE'])->id, 'campus_id' => $this->otherCampus->id]);
    CareerCampus::factory()->inactive()->create(['career_id' => Career::factory()->create(['code' => 'IMEC'])->id, 'campus_id' => $this->campus->id]);
    CareerCampus::factory()->create(['career_id' => Career::factory()->inactive()->create(['code' => 'IQUI'])->id, 'campus_id' => $this->campus->id]);
});

/**
 * @return list<string>
 */
function plannerCareersFor(User $user): array
{
    test()->actingAs($user);

    return array_column(app(PlanSemestersQuery::class)->careers(app(Actor::class), test()->term, test()->campus), 'code');
}

/**
 * Un grupo de la carrera ISC en el periodo y campus de la prueba, con las horas de su materia (teoría + práctica).
 */
function plannerGroup(StudyPlan $plan, int $semester, int $hours = 5, array $attributes = []): Group
{
    return Group::factory()->create([
        'term_id' => test()->term->id,
        'campus_id' => test()->campus->id,
        'owner_career_id' => $plan->career_id,
        'study_plan_id' => $plan->id,
        'semester' => $semester,
        'subject_id' => fn () => Subject::factory()->create(['theory_hours' => $hours, 'practice_hours' => 0])->id,
        ...$attributes,
    ]);
}

it('al admin y a la Subdirección les da las carreras activas que se ofrecen en el campus, por clave', function (Closure $user) {
    expect(plannerCareersFor($user($this)))->toBe(['IIND', 'ISC']);
})->with([
    'admin' => [fn () => User::factory()->admin()->create()],
    'subdirección' => [fn () => User::factory()->withRole(Role::Subdireccion)->create()],
]);

it('al coordinador, solo su carrera en su campus', function () {
    $coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->isc->id])->create();
    $elsewhere = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->otherCampus->id, 'career_id' => $this->isc->id])->create();

    expect(plannerCareersFor($coordinator))->toBe(['ISC'])
        ->and(plannerCareersFor($elsewhere))->toBe([]);
});

it('al jefe, las carreras de su departamento; al de otro departamento, ninguna', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sistemas->id])->create();
    $other = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => Department::factory()->create()->id])->create();

    expect(plannerCareersFor($jefe))->toBe(['ISC'])
        ->and(plannerCareersFor($other))->toBe([]);
});

it('al jefe de un departamento que imparte en otra carrera, la carrera donde tiene grupos activos en el periodo y campus', function () {
    $plan = StudyPlan::factory()->create(['career_id' => $this->isc->id]);
    $calculo = Subject::factory()->create(['department_id' => $this->basicas->id]);
    plannerGroup($plan, 1, attributes: ['subject_id' => $calculo->id]);
    plannerGroup($plan, 1, attributes: ['subject_id' => $calculo->id, 'term_id' => Term::factory()->create()->id]);
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->basicas->id])->create();

    expect(plannerCareersFor($jefe))->toBe(['ISC']);

    Group::query()->update(['status' => 'cancelled']);

    expect(plannerCareersFor($jefe))->toBe([]);
});

it('al docente, ninguna', function () {
    expect(plannerCareersFor(User::factory()->withRole(Role::Docente)->create()))->toBe([]);
});

it('careerOrFail da la carrera solo si el actor la ve', function () {
    $this->actingAs(User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->iind->id])->create());
    $query = app(PlanSemestersQuery::class);

    expect($query->careerOrFail(app(Actor::class), $this->term, $this->campus, $this->iind->id)->is($this->iind))->toBeTrue()
        ->and(fn () => $query->careerOrFail(app(Actor::class), $this->term, $this->campus, $this->isc->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $query->careerOrFail(app(Actor::class), $this->term, $this->campus, 'no-es-uuid'))->toThrow(ModelNotFoundException::class);
});

it('da los planes activos de la carrera, el vigente primero y los viejos con la marca de prioridad, con sus grupos sin acomodar', function () {
    $current = StudyPlan::factory()->create(['career_id' => $this->isc->id, 'code' => 'ISIC-2010-224', 'name' => 'Plan 2010', 'is_current' => true]);
    $old = StudyPlan::factory()->create(['career_id' => $this->isc->id, 'code' => 'ISIC-2004-296', 'name' => 'Plan 2004']);
    $older = StudyPlan::factory()->create(['career_id' => $this->isc->id, 'code' => 'ISIC-2009-226', 'name' => 'Plan 2009']);
    StudyPlan::factory()->inactive()->create(['career_id' => $this->isc->id, 'code' => 'ISIC-1993-001']);

    $complete = plannerGroup($current, 1, 4);
    GroupSession::factory()->for($complete)->on(1, '07:00', '09:00')->create();
    GroupSession::factory()->for($complete)->on(3, '07:00', '09:00')->create();
    $partial = plannerGroup($current, 2, 4);
    GroupSession::factory()->for($partial)->on(1, '07:00', '10:00')->create();
    plannerGroup($current, 1, attributes: ['status' => 'cancelled']);
    plannerGroup($current, 1, attributes: ['campus_id' => $this->otherCampus->id]);
    plannerGroup($old, 3);

    $plans = app(PlanSemestersQuery::class)->plans($this->isc, $this->term, $this->campus);

    expect($plans)->toBe([
        ['id' => $current->id, 'code' => 'ISIC-2010-224', 'name' => 'Plan 2010', 'is_current' => true, 'is_priority' => false, 'groups' => 2, 'unscheduled' => 1],
        ['id' => $old->id, 'code' => 'ISIC-2004-296', 'name' => 'Plan 2004', 'is_current' => false, 'is_priority' => true, 'groups' => 1, 'unscheduled' => 1],
        ['id' => $older->id, 'code' => 'ISIC-2009-226', 'name' => 'Plan 2009', 'is_current' => false, 'is_priority' => true, 'groups' => 0, 'unscheduled' => 0],
    ]);
});

it('planOrFail da solo un plan activo de la carrera', function () {
    $plan = StudyPlan::factory()->create(['career_id' => $this->isc->id]);
    $inactive = StudyPlan::factory()->inactive()->create(['career_id' => $this->isc->id]);
    $foreign = StudyPlan::factory()->create(['career_id' => $this->iind->id]);
    $query = app(PlanSemestersQuery::class);

    expect($query->planOrFail($this->isc, $plan->id)->is($plan))->toBeTrue()
        ->and(fn () => $query->planOrFail($this->isc, $inactive->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $query->planOrFail($this->isc, $foreign->id))->toThrow(ModelNotFoundException::class);
});

it('da los semestres del plan, del primero al último, con sus grupos y los que tienen horas sin acomodar', function () {
    $plan = StudyPlan::factory()->create(['career_id' => $this->isc->id, 'semesters_count' => 4]);
    $complete = plannerGroup($plan, 1, 2);
    GroupSession::factory()->for($complete)->on(2, '08:00', '10:00')->create();
    plannerGroup($plan, 1, 2);
    plannerGroup($plan, 3, 3);
    plannerGroup($plan, 3, 3, ['term_id' => Term::factory()->create()->id]);

    expect(app(PlanSemestersQuery::class)->semesters($plan, $this->term, $this->campus))->toBe([
        ['semester' => 1, 'groups' => 2, 'unscheduled' => 1],
        ['semester' => 2, 'groups' => 0, 'unscheduled' => 0],
        ['semester' => 3, 'groups' => 1, 'unscheduled' => 1],
        ['semester' => 4, 'groups' => 0, 'unscheduled' => 0],
    ]);
});

it('sin número de semestres capturado, llega al último semestre de su retícula o de sus grupos', function () {
    $plan = StudyPlan::factory()->create(['career_id' => $this->isc->id, 'semesters_count' => null]);
    placeInPlan($plan, Subject::factory()->create(), 2);

    expect(array_column(app(PlanSemestersQuery::class)->semesters($plan, $this->term, $this->campus), 'semester'))->toBe([1, 2]);

    plannerGroup($plan, 3);

    expect(array_column(app(PlanSemestersQuery::class)->semesters($plan, $this->term, $this->campus), 'semester'))->toBe([1, 2, 3]);
});
