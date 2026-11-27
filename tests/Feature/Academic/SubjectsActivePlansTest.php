<?php

use App\Modules\Academic\Contracts\ActiveStudyPlans;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Queries\ActiveStudyPlansQuery;
use Livewire\Livewire;

/*
| SEL-19: el jefe de departamento ve en Materias solo las de retículas activas, planes con al menos un alumno activo
| (confirmado el 27 de noviembre de 2026); baja temporal no cuenta y el admin ve todas (supuestos por confirmar).
*/

beforeEach(function () {
    $this->sis = Department::factory()->create(['code' => 'SIS']);
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sis->id])->create();
    $this->mk = fn (string $code): Subject => Subject::factory()->create(['code' => $code, 'department_id' => $this->sis->id]);
    $this->inPlanWith = function (Subject $subject, ?string $status): StudyPlan {
        $plan = StudyPlan::factory()->create();
        placeInPlan($plan, $subject);

        if ($status !== null) {
            Student::factory()->for($plan)->create(['career_id' => $plan->career_id, 'status' => $status]);
        }

        return $plan;
    };
});

it('SEL-19: el jefe ve la materia de un plan con un alumno activo y no la de un plan sin alumnos', function () {
    $activa = ($this->mk)('ACT-0001');
    $vacia = ($this->mk)('VAC-0001');
    ($this->inPlanWith)($activa, 'activo');
    ($this->inPlanWith)($vacia, null);
    actingAsRole($this->jefe, Role::JefeDepartamento);

    Livewire::test('academic::pages.subjects')->assertSee('ACT-0001')->assertDontSee('VAC-0001');
});

it('SEL-19: una materia fuera de todo plan no sale en la lista del jefe', function () {
    ($this->mk)('SOL-0001');
    actingAsRole($this->jefe, Role::JefeDepartamento);

    Livewire::test('academic::pages.subjects')->assertDontSee('SOL-0001');
});

it('SEL-19: baja temporal, baja definitiva y egresado no vuelven activa la retícula', function (string $status) {
    $subject = ($this->mk)('BAJ-0001');
    ($this->inPlanWith)($subject, $status);
    actingAsRole($this->jefe, Role::JefeDepartamento);

    Livewire::test('academic::pages.subjects')->assertDontSee('BAJ-0001');
})->with(['baja_temporal', 'baja_definitiva', 'egresado']);

it('SEL-19: una materia en dos planes, uno activo y otro no, sale una vez y cuenta sus dos planes', function () {
    $subject = ($this->mk)('DOS-0001');
    ($this->inPlanWith)($subject, 'activo');
    ($this->inPlanWith)($subject, null);
    actingAsRole($this->jefe, Role::JefeDepartamento);

    $page = Livewire::test('academic::pages.subjects')->assertSee('DOS-0001');

    expect($page->viewData('subjects')->total())->toBe(1)
        ->and($page->viewData('subjects')->first()->study_plans_count)->toBe(2);
});

it('SEL-19: el admin ve las materias de retículas activas y de las que no', function () {
    ($this->inPlanWith)(($this->mk)('ACT-0001'), 'activo');
    ($this->inPlanWith)(($this->mk)('VAC-0001'), null);
    ($this->inPlanWith)(($this->mk)('BAJ-0001'), 'baja_temporal');
    actingAsRole(User::factory()->admin()->create(), Role::Admin);

    Livewire::test('academic::pages.subjects')->assertSee('ACT-0001')->assertSee('VAC-0001')->assertSee('BAJ-0001');
});

it('SEL-19: el jefe recibe 404 al pedir la materia oculta, en espacio y en horas de docente', function (string $method) {
    $oculta = ($this->mk)('VAC-0001');
    ($this->inPlanWith)($oculta, 'baja_definitiva');
    actingAsRole($this->jefe, Role::JefeDepartamento);

    Livewire::test('academic::pages.subjects')->call($method, $oculta->id)->assertNotFound();
})->with(['editSpace', 'editTeacherHours']);

it('SEL-19: el jefe sigue editando el espacio de una materia visible', function () {
    $visible = ($this->mk)('ACT-0001');
    ($this->inPlanWith)($visible, 'activo');
    actingAsRole($this->jefe, Role::JefeDepartamento);

    Livewire::test('academic::pages.subjects')->call('editSpace', $visible->id)->assertOk()->assertSet('spaceSubjectId', $visible->id);
});

it('SEL-19: Students devuelve los planes con al menos un alumno activo, sin repetir', function () {
    $activo = StudyPlan::factory()->create();
    Student::factory()->count(2)->for($activo)->create(['career_id' => $activo->career_id, 'status' => 'activo']);
    $baja = StudyPlan::factory()->create();
    Student::factory()->for($baja)->create(['career_id' => $baja->career_id, 'status' => 'baja_temporal']);
    $borrado = StudyPlan::factory()->create();
    Student::factory()->for($borrado)->create(['career_id' => $borrado->career_id, 'status' => 'activo'])->delete();

    expect(app(ActiveStudyPlans::class))->toBeInstanceOf(ActiveStudyPlansQuery::class)
        ->and(app(ActiveStudyPlans::class)->ids())->toBe([$activo->id]);
});
