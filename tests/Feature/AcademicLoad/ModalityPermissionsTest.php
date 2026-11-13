<?php

use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;

/*
| AZ-S17 (M5, M7, M9; MS6 y MS8, supuestos por confirmar): la carga, la propuesta y la bandeja se leen a nivel de
| modalidad, sin el campus; solo una fila de la propuesta, que es de un campus, declara también el campus. EAD se
| activa en estas pruebas.
*/

beforeEach(function () {
    $this->pres = modalityOf('PRES');
    $this->ead = modalityOf('EAD');
    $this->ead->update(['is_active' => true]);
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->load = AcademicLoad::factory()->create(['modality_id' => modalityOf('PRES')->id, 'department_id' => departmentKey(10)]);
    $this->proposal = CareerProposal::factory()->create(['career_id' => careerKey(20)]);
    $this->review = ProposalReview::factory()->create(['career_proposal_id' => $this->proposal->id, 'department_id' => departmentKey(10)]);
});

it('AZ-S17: un coordinador de PRES limitado a C2 ve la propuesta de su carrera y corrige solo las filas de C2', function () {
    $coordinador = User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->pres->id, 'campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create();
    $inC1 = ProposalItem::factory()->create(['career_proposal_id' => $this->proposal->id, 'campus_id' => $this->c1->id]);
    $inC2 = ProposalItem::factory()->create(['career_proposal_id' => $this->proposal->id, 'campus_id' => $this->c2->id]);

    expect($coordinador->can('view', $this->proposal))->toBeTrue()
        ->and($coordinador->can('update', [$this->proposal, $inC1]))->toBeFalse()
        ->and($coordinador->can('update', [$this->proposal, $inC2]))->toBeTrue();
});

it('AZ-S17: un jefe de proyecto docente de EAD no ve nada de una carga ni de una propuesta de PRES', function () {
    $jpd = User::factory()->withRole(Role::JefeProyectoDocente, ['modality_id' => $this->ead->id, 'department_id' => departmentKey(10)])->create();

    expect($jpd->can('view', $this->load))->toBeFalse()
        ->and($jpd->can('viewAny', [ProposalReview::class, $this->pres, $this->load->department]))->toBeFalse()
        ->and($jpd->can('decide', $this->review))->toBeFalse();
});

it('AZ-S17: un coordinador de EAD no ve ni corrige la propuesta de PRES', function () {
    $coordinador = User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->ead->id, 'career_id' => careerKey(20)])->create();

    expect($coordinador->can('view', $this->proposal))->toBeFalse()
        ->and($coordinador->can('update', [$this->proposal, ProposalItem::factory()->create(['career_proposal_id' => $this->proposal->id, 'campus_id' => $this->c1->id])]))->toBeFalse()
        ->and($coordinador->can('create', [CareerProposal::class, $this->pres, $this->proposal->career]))->toBeFalse();
});

it('AZ-S17: un jefe de proyecto docente de PRES con campus ve la carga de otro campus de su modalidad', function () {
    $jpd = User::factory()->withRole(Role::JefeProyectoDocente, ['modality_id' => $this->pres->id, 'campus_id' => $this->c2->id, 'department_id' => departmentKey(10)])->create();

    expect($jpd->can('view', $this->load))->toBeTrue()
        ->and($jpd->can('propose', $this->load))->toBeFalse();
});

it('AZ-S17: un jefe de departamento sin modalidad conserva el acceso a PRES', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])->create();

    expect($jefe->can('view', $this->load))->toBeTrue()
        ->and($jefe->can('propose', $this->load))->toBeTrue()
        ->and($jefe->can('decide', $this->review))->toBeTrue();
});
