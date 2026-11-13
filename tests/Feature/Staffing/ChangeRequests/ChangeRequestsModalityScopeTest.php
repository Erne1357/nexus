<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

require_once __DIR__.'/ChangeRequestScreensSupport.php';

/*
| Alcance de «Solicitudes de cambio» (maqueta U2, opción A; DESIGN.md, «Alcance de la carga»): la página es de una
| modalidad (`?modalidad=`, M1), con las solicitudes de los grupos de todos sus campus y el campus como filtro con su
| cifra (`?campus=`, «Todos» por omisión); cada renglón lleva el campus de hoy de su grupo (M8). Ver es de la modalidad
| (MS6): el jefe de proyecto docente limitado a C2 lee las de C1 sin decidir ni aplicar; aplicar sigue con el campus del
| grupo.
*/

beforeEach(function () {
    $this->w = crWorld();
    $this->c2 = $this->w->campus;
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->inC2 = crGroup($this->w, '1A', 'Fundamentos de Programación', $this->w->sc, $this->w->isc, $this->w->teacher);
    $this->inC1 = crGroup($this->w, '5A', 'Bases de Datos', $this->w->sc, $this->w->isc, $this->w->otherTeacher);
    $this->inC1->update(['campus_id' => $this->c1->id]);
    $this->requestC2 = crRequest($this->inC2, $this->w->teacherUser, 'pending');
    $this->requestC1 = crRequest($this->inC1->fresh(), $this->w->otherTeacherUser, 'approved');
    $this->pres = modalityOf('PRES');
    $this->jpdC2 = User::factory()->withRole(Role::JefeProyectoDocente, ['modality_id' => $this->pres->id, 'campus_id' => $this->c2->id, 'department_id' => $this->w->sc->id])->create();
});

/**
 * @param  array<string, mixed>  $query
 */
function crScopePage(User $user, array $query = []): Testable
{
    return Livewire::withQueryParams($query)->actingAs($user)->test('staffing::pages.change-requests');
}

it('MS6: el jefe de proyecto docente limitado a C2 lee las solicitudes de C1 y C2, cada una con su campus, sin decidir ni aplicar', function () {
    crScopePage($this->jpdC2)
        ->assertSet('campus', null)
        ->assertSet('modalidad', 'PRES')
        ->assertSeeHtml('data-request="'.$this->requestC1->id.'"')
        ->assertSeeHtml('data-request="'.$this->requestC2->id.'"')
        ->assertSeeHtml('data-testid="request-campus-'.$this->requestC1->id.'"')
        ->call('pick', $this->requestC1->id)
        ->assertDontSeeHtml('data-testid="approve-request"')
        ->call('markApplied')
        ->assertForbidden();

    expect($this->requestC1->fresh()->status)->toBe(ChangeRequestStatus::Approved);
});

it('U2: el filtro de campus lleva las solicitudes de cada campus y deja solo las del elegido, en la misma modalidad', function () {
    $page = crScopePage($this->w->jefeSc)
        ->assertSeeHtml('data-testid="campus-count-todos">2<')
        ->assertSeeHtml('data-testid="campus-count-C1">1<')
        ->assertSeeHtml('data-testid="campus-count-C2">1<')
        ->assertSee('Filtra las solicitudes por el campus de su grupo; la carga es de toda la modalidad.');

    $page->set('campus', 'C1')
        ->assertSeeHtml('data-request="'.$this->requestC1->id.'"')
        ->assertDontSeeHtml('data-request="'.$this->requestC2->id.'"')
        ->assertSet('modalidad', 'PRES');
});

it('?solicitud= de un grupo de C1 con el filtro en C2 abre la solicitud y pasa el filtro a «Todos»', function () {
    crScopePage($this->w->jefeSc, ['campus' => 'C2', 'solicitud' => $this->requestC1->id])
        ->assertSet('requestId', $this->requestC1->id)
        ->assertSet('campus', null)
        ->assertSeeHtml('data-testid="request-detail" data-request-id="'.$this->requestC1->id.'"');
});

it('MS6: el coordinador limitado a C2 no ve ni aplica la solicitud de horario de un grupo de C1, ni la pide desde aquí', function () {
    $coordC2 = User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->pres->id, 'campus_id' => $this->c2->id, 'career_id' => $this->w->isc->id])->create();

    crScopePage($coordC2)
        ->assertDontSeeHtml('data-request="'.$this->requestC1->id.'"')
        ->call('pick', $this->requestC1->id)
        ->assertNotFound();

    $page = crScopePage($coordC2)->call('openRequest');
    preg_match_all('/data-group-option="([0-9a-f-]+)"/', $page->html(), $options);

    expect($options[1])->toContain($this->inC2->id)->not->toContain($this->inC1->id);
});

it('otra modalidad: un jefe de proyecto docente de EAD no ve las solicitudes de PRES ni abre una por su id', function () {
    modalityOf('EAD')->update(['is_active' => true]);
    $jpdEad = User::factory()->withRole(Role::JefeProyectoDocente, ['modality_id' => modalityOf('EAD')->id, 'department_id' => $this->w->sc->id])->create();

    crScopePage($jpdEad, ['modalidad' => 'PRES'])
        ->assertSet('modalidad', 'EAD')
        ->assertDontSeeHtml('data-request="'.$this->requestC1->id.'"')
        ->assertDontSeeHtml('data-request="'.$this->requestC2->id.'"');

    crScopePage($jpdEad, ['solicitud' => $this->requestC2->id])->assertNotFound();
});
