<?php

use App\Modules\Academic\Models\CareerCampus;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadTransitioning;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Staffing\AssignmentWorld;

/*
| «Pasar a asignación docente» desde la asignación (tarea 3.9): si ya se sabe que Scheduling lo vetaría (grupos con
| choques duros o sin horario), el botón sale deshabilitado con los motivos en palabras llanas y la liga para
| arreglarlo; si no, el botón está activo. Los motivos de un veto al pasarla tampoco citan ids de regla.
*/

beforeEach(function () {
    $this->world = AssignmentWorld::create(LoadStatus::Proposed);
    $isc = $this->world->career('ISC');
    CareerCampus::factory()->create(['career_id' => $isc->id, 'campus_id' => $this->world->campus->id]);
    $this->subject = $this->world->subject('SCC-1013', 'Programación Orientada a Objetos');
    $this->subject->update(['theory_hours' => 2, 'practice_hours' => 0]);
    $this->isc = $isc;
    $this->world->group($this->subject, $isc, 'D', 2, [[1, '10:00', '12:00']], ['capacity' => 30]);

    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->world->department->id])->create();
});

function startStaffingPage(User $user): Testable
{
    test()->withSession([Actor::ACTIVE_CAMPUS => test()->world->campus->id]);

    return Livewire::actingAs($user)->test('staffing::pages.assign');
}

it('sin choques duros ni grupos sin horario, «Pasar a asignación docente» está activo', function () {
    startStaffingPage($this->jefe)
        ->assertSeeHtml('data-testid="start-staffing"')
        ->assertDontSeeHtml('data-testid="start-staffing-disabled"')
        ->assertDontSeeHtml('data-testid="staffing-blockers"');
});

it('con grupos sin horario el botón sale deshabilitado con el motivo y la liga a Choques y avance', function () {
    $this->world->group($this->subject, $this->isc, '1', 2, [], ['capacity' => 30]);
    $this->world->group($this->subject, $this->isc, '2', 2, [], ['capacity' => 30]);

    startStaffingPage($this->jefe)
        ->assertSeeHtml('data-testid="start-staffing-disabled"')
        ->assertDontSeeHtml('data-testid="start-staffing"')
        ->assertSeeHtml('data-testid="staffing-blockers"')
        ->assertSeeText('2 grupos no tienen horario.')
        ->assertSeeHtml('href="'.route('scheduling.conflicts').'"')
        ->assertDontSeeText('HC-');
});

it('el veto al pasarla muestra sus motivos sin ids de regla', function () {
    Event::listen(LoadTransitioning::class, fn (LoadTransitioning $event) => $event->block('3 grupos tienen choques duros: corrígelos en el editor de horarios antes de asignar docentes (HC-15).'));

    startStaffingPage($this->jefe)
        ->call('startStaffing')
        ->assertSeeHtml('data-testid="veto-reasons"')
        ->assertSeeText('3 grupos tienen choques duros: corrígelos en el editor de horarios antes de asignar docentes.')
        ->assertDontSeeText('(HC-15)');

    expect($this->world->load->fresh()->status)->toBe(LoadStatus::Proposed);
});
