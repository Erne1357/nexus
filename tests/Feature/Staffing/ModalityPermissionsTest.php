<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Models\StaffingRanking;
use App\Modules\Staffing\Models\TeacherTermLoad;
use App\Modules\Staffing\Queries\AssignmentBoardQuery;
use Livewire\Livewire;
use Tests\Support\Staffing\AssignmentWorld;

/*
| AZ-S17 (M5, M9; MS6 y MS8, supuestos por confirmar): la asignación docente se lee a nivel de modalidad, sin el
| campus; asignar el docente de un grupo, que es de un campus, exige además el campus. EAD se activa en estas pruebas.
*/

beforeEach(function () {
    $this->world = AssignmentWorld::create();
    $this->pres = modalityOf('PRES');
    $this->ead = modalityOf('EAD');
    $this->ead->update(['is_active' => true]);
    $this->c2 = $this->world->campus;
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->isc = $this->world->career('ISC');
    $this->poo = $this->world->subject('SCC-1013', 'Programación Orientada a Objetos');
    $this->groupC2 = $this->world->group($this->poo, $this->isc, 'D', 2, [[1, '10:00', '11:00']]);
    $this->groupC1 = $this->world->group($this->poo, $this->isc, 'E', 2, [[2, '10:00', '11:00']], ['campus_id' => $this->c1->id]);
    // M1: el grupo de C1 es de la misma carga presencial del departamento que los de C2.
    $this->teacher = $this->world->teacher('Ana Ruiz');
    $this->world->rank($this->groupC2, [$this->teacher]);
    $this->world->rank($this->groupC1, [$this->teacher]);
    $this->department = $this->world->department;
});

it('AZ-S17: un jefe de proyecto docente de PRES limitado a C2 ve el tablero con grupos de C1 y C2 y asigna solo en C2', function () {
    $jpd = User::factory()->withRole(Role::JefeProyectoDocente, ['modality_id' => $this->pres->id, 'campus_id' => $this->c2->id, 'department_id' => $this->department->id])->create();

    expect($jpd->can('viewAny', [StaffingRanking::class, $this->groupC1]))->toBeTrue()
        ->and($jpd->can('viewAny', [StaffingRanking::class, $this->groupC2]))->toBeTrue()
        ->and($jpd->can('create', [GroupTeacherAssignment::class, $this->groupC2]))->toBeTrue()
        ->and($jpd->can('create', [GroupTeacherAssignment::class, $this->groupC1]))->toBeFalse();

    $actor = app(Actor::class)->forUser($jpd);
    $query = app(AssignmentBoardQuery::class);
    $board = $query->board($this->world->term->id, $this->pres->id, $this->department->id);

    expect($query->departmentsFor($actor, $this->pres->id)->modelKeys())->toBe([$this->department->id])
        ->and(array_map(fn ($row): string => $row->group->campus->code, $board->groups))->toEqualCanonicalizing(['C1', 'C2']);

    onCampusPage($this->c2);
    $page = Livewire::withQueryParams(['depto' => 'SC'])->actingAs($jpd)->test('staffing::pages.assign');
    $page->assertOk();
    $page->call('assign', $this->groupC2->id, $this->teacher->id, 'tarjetas');

    expect($this->groupC2->fresh()->teacher_id)->toBe($this->teacher->id)
        ->and($this->groupC1->fresh()->teacher_id)->toBeNull();
});

it('AZ-S17, MS6: el jefe de proyecto docente de PRES limitado a C2 ve el grupo de C1 y asignarle docente es 403 de la policy', function () {
    $jpd = User::factory()->withRole(Role::JefeProyectoDocente, ['modality_id' => $this->pres->id, 'campus_id' => $this->c2->id, 'department_id' => $this->department->id])->create();

    onCampusPage($this->c2);
    Livewire::withQueryParams(['depto' => 'SC'])->actingAs($jpd)->test('staffing::pages.assign')
        ->assertOk()
        ->assertSeeHtml('data-group="'.$this->groupC1->id.'"')
        ->call('assign', $this->groupC1->id, $this->teacher->id, 'tarjetas')
        ->assertForbidden();

    expect($this->groupC1->fresh()->teacher_id)->toBeNull()
        ->and(GroupTeacherAssignment::query()->where('group_id', $this->groupC1->id)->exists())->toBeFalse();
});

it('AZ-S17: un jefe de proyecto docente de EAD no ve nada de PRES', function () {
    $jpd = User::factory()->withRole(Role::JefeProyectoDocente, ['modality_id' => $this->ead->id, 'department_id' => $this->department->id])->create();

    expect($jpd->can('viewAny', [StaffingRanking::class, $this->groupC2]))->toBeFalse()
        ->and($jpd->can('create', [GroupTeacherAssignment::class, $this->groupC2]))->toBeFalse()
        ->and($jpd->can('viewAny', [TeacherTermLoad::class, $this->department, $this->pres]))->toBeFalse()
        ->and($jpd->can('viewAny', [TeacherTermLoad::class, $this->department, $this->ead]))->toBeTrue();
});

it('AZ-S17: un jefe de departamento sin modalidad conserva el acceso a PRES', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->department->id])->create();

    expect($jefe->can('viewAny', [StaffingRanking::class, $this->groupC1]))->toBeTrue()
        ->and($jefe->can('create', [GroupTeacherAssignment::class, $this->groupC1]))->toBeTrue()
        ->and($jefe->can('create', [GroupTeacherAssignment::class, $this->groupC2]))->toBeTrue();
});

it('AZ-S17: un coordinador de PRES limitado a C2 pide cambios solo en grupos de C2 y uno de EAD en ninguno', function () {
    $coordinador = User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->pres->id, 'campus_id' => $this->c2->id, 'career_id' => $this->isc->id])->create();
    $ead = User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->ead->id, 'career_id' => $this->isc->id])->create();

    expect($coordinador->can('create', [ChangeRequest::class, $this->groupC2]))->toBeTrue()
        ->and($coordinador->can('create', [ChangeRequest::class, $this->groupC1]))->toBeFalse()
        ->and($ead->can('create', [ChangeRequest::class, $this->groupC2]))->toBeFalse();
});

it('AZ-S17: la solicitud de cambio propia de un docente (personal) sigue funcionando', function () {
    $user = User::factory()->withRole(Role::Docente)->create();
    $this->teacher->update(['user_id' => $user->id]);
    $this->groupC2->update(['teacher_id' => $this->teacher->id]);

    expect($user->can('create', [ChangeRequest::class, $this->groupC2->fresh()]))->toBeTrue();
});
