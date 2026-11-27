<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Queries\ConflictBoardQuery;

/*
| AZ-S17 (M7, M8; MS6, supuesto por confirmar): los horarios y el tablero se leen a nivel de modalidad, sin el campus;
| lo que mueve sesiones, grupos o salones de un campus exige además ese campus. EAD se activa en estas pruebas.
*/

beforeEach(function () {
    $this->pres = modalityOf('PRES');
    $this->ead = modalityOf('EAD');
    $this->ead->update(['is_active' => true]);
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->term = Term::factory()->create();
    $this->career = Career::factory()->create(['department_id' => departmentKey(10)]);
    $this->plan = StudyPlan::factory()->create(['career_id' => $this->career->id, 'is_current' => true]);
    $this->subject = Subject::factory()->create(['department_id' => departmentKey(11)]);
    $this->inC1 = scheduleGroupIn($this->c1);
    $this->inC2 = scheduleGroupIn($this->c2);
});

function scheduleGroupIn(Campus $campus): Group
{
    return Group::factory()->create([
        'term_id' => test()->term->id, 'campus_id' => $campus->id, 'subject_id' => test()->subject->id,
        'owner_career_id' => test()->career->id, 'study_plan_id' => test()->plan->id, 'semester' => 1,
    ]);
}

/** @return list<string> los grupos del tablero de la modalidad, filtrado a un campus, que el actor ve */
function boardGroupIds(User $user, Campus $campus): array
{
    return app(ConflictBoardQuery::class)
        ->statuses(app(Actor::class)->forUser($user), test()->term, test()->pres, $campus)
        ->map(fn ($status) => $status->id)->values()->all();
}

it('AZ-S17: un coordinador de PRES limitado a C2 ve los grupos de C1 y C2 de su carrera, pero solo mueve los de C2', function () {
    $coordinador = User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->pres->id, 'campus_id' => $this->c2->id, 'career_id' => $this->career->id])->create();

    expect($coordinador->can('view', $this->inC1))->toBeTrue()
        ->and(boardGroupIds($coordinador, $this->c1))->toBe([$this->inC1->id])
        ->and($coordinador->can('editSessions', $this->inC1))->toBeFalse()
        ->and($coordinador->can('manage', $this->inC1))->toBeFalse()
        ->and($coordinador->can('editSessions', $this->inC2))->toBeTrue()
        ->and($coordinador->can('manage', $this->inC2))->toBeTrue();
});

it('AZ-S17: un jefe de proyecto docente de PRES limitado a C2 ve los grupos de C1 en el tablero', function () {
    $jpd = User::factory()->withRole(Role::JefeProyectoDocente, ['modality_id' => $this->pres->id, 'campus_id' => $this->c2->id, 'department_id' => departmentKey(11)])->create();

    expect($jpd->can('view', $this->inC1))->toBeTrue()
        ->and(boardGroupIds($jpd, $this->c1))->toBe([$this->inC1->id])
        ->and(boardGroupIds($jpd, $this->c2))->toBe([$this->inC2->id]);
});

it('AZ-S17: mecanismo de la dimensión Campus en RoomAssign: un puesto limitado a C2 no asigna salón en C1', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->c2->id, 'department_id' => departmentKey(11)])->create();

    expect($jefe->can('assignRoom', $this->inC1))->toBeFalse()
        ->and($jefe->can('assignRoom', $this->inC2))->toBeTrue();
});

it('AZ-S17: un jefe de proyecto docente y un coordinador de EAD no ven nada de PRES', function () {
    $jpd = User::factory()->withRole(Role::JefeProyectoDocente, ['modality_id' => $this->ead->id, 'department_id' => departmentKey(11)])->create();
    $coordinador = User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->ead->id, 'career_id' => $this->career->id])->create();

    expect($jpd->can('view', $this->inC1))->toBeFalse()
        ->and(boardGroupIds($jpd, $this->c1))->toBe([])
        ->and($coordinador->can('view', $this->inC1))->toBeFalse()
        ->and($coordinador->can('editSessions', $this->inC1))->toBeFalse()
        ->and(boardGroupIds($coordinador, $this->c2))->toBe([]);
});

it('AZ-S17: un jefe de departamento sin modalidad conserva el acceso a PRES', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(11)])->create();

    expect($jefe->can('view', $this->inC1))->toBeTrue()
        ->and($jefe->can('assignRoom', $this->inC1))->toBeTrue()
        ->and(boardGroupIds($jefe, $this->c1))->toBe([$this->inC1->id]);
});
