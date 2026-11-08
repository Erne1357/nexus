<?php

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Models\ChangeRequest;

require_once __DIR__.'/../../Feature/Staffing/ChangeRequests/ChangeRequestScreensSupport.php';

/*
| Solicitud de cambio en el navegador (tarea 3.11): la docente pide un cambio de horario desde «Mi carga», el jefe del
| departamento lo aprueba (la carga pasa a Ajustes) y la coordinadora de la carrera dueña lo marca como aplicado (la
| carga regresa a Asignación).
*/

beforeEach(function () {
    $this->w = crWorld();
    $this->w->teacherUser->update(['username' => 'marisol']);
    $this->w->jefeSc->update(['username' => 'jefe-sc']);
    $this->w->coordIsc->update(['username' => 'coord-isc']);
    $this->group = crGroup($this->w, '1A', 'Fundamentos de Programación', $this->w->sc, $this->w->isc, $this->w->teacher);
});

function crSignIn(string $username)
{
    return visit('/login')
        ->fill('username', $username)
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/');
}

it('la docente pide un cambio de horario, el jefe lo aprueba y la coordinadora lo marca como aplicado', function () {
    crSignIn('marisol')
        ->navigate('/mi-espacio/mi-carga')
        ->assertSee('Mis solicitudes')
        ->click('[data-testid="request-change-'.$this->group->id.'"]')
        ->waitForText('Qué quieres cambiar')
        ->fill('[data-testid="request-proposal"]', 'Martes y jueves de 10:00 a 12:00')
        ->fill('[data-testid="request-reason"]', 'Los lunes atiendo residencias en Campus I.')
        ->click('[data-testid="send-request"]')
        ->waitForText('Enviaste tu solicitud de cambio del grupo 1A')
        ->assertSeeIn('[data-testid="my-requests"]', 'Martes y jueves de 10:00 a 12:00')
        ->assertSeeIn('[data-testid="my-requests"]', 'Pendiente')
        ->assertNoJavaScriptErrors()
        ->click('@credential')
        ->press('Cerrar sesión')
        ->assertPathIs('/login');

    $request = ChangeRequest::query()->sole();

    crSignIn('jefe-sc')
        ->navigate('/staffing/change-requests')
        ->assertSee('Solicitudes de cambio')
        ->assertSeeIn('[data-testid="request-detail"]', 'Los lunes atiendo residencias en Campus I.')
        ->fill('[data-testid="decision-comment"]', 'De acuerdo, el bloque de 10:00 está libre.')
        ->click('[data-testid="approve-request"]')
        ->waitForText('Aprobaste la solicitud')
        ->assertSeeIn('[data-testid="load-status"]', 'Ajustes')
        ->assertSeeIn('[data-testid="request-detail"]', 'La aplica la coordinación de ISC en el editor de horarios.')
        ->assertNoJavaScriptErrors()
        ->click('@credential')
        ->press('Cerrar sesión')
        ->assertPathIs('/login');

    expect($request->fresh()->status)->toBe(ChangeRequestStatus::Approved)
        ->and($this->w->scLoad->fresh()->status)->toBe(LoadStatus::Adjusting);

    crSignIn('coord-isc')
        ->navigate('/staffing/change-requests?solicitud='.$request->id)
        ->assertSeeIn('[data-testid="request-detail"]', 'De acuerdo, el bloque de 10:00 está libre.')
        ->click('[data-testid="apply-request"]')
        ->waitForText('Marcaste la solicitud como aplicada')
        ->assertSeeIn('[data-testid="load-status"]', 'Asignación')
        ->assertSeeIn('[data-testid="request-history"]', 'Aplicó')
        ->assertNoJavaScriptErrors();

    expect($request->fresh()->status)->toBe(ChangeRequestStatus::Applied)
        ->and($this->w->scLoad->fresh()->status)->toBe(LoadStatus::Staffing);
});
