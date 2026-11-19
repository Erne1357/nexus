<?php

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Staffing\Enums\ChangeRequestStatus;

require_once __DIR__.'/../../Feature/Staffing/ChangeRequests/ChangeAlternativesSupport.php';

/*
| Aprobar con una alternativa del motor en el navegador (tarea 8.7, maqueta A «Pestañas»): la solicitud del docente
| choca con otro grupo en el salón (HC-02), así que el jefe no puede aprobar lo pedido; busca alternativas, el motor
| corre (en la cola, `sync` en las pruebas) y la pantalla abre la alternativa A; el jefe la aprueba y la carga pasa a
| Ajustes.
*/

it('el jefe busca alternativas, elige la A y la aprueba', function () {
    $w = altWorld();
    $w->jefe->update(['username' => 'jefe-sc']);
    $request = altRequest($w);

    visit('/login')
        ->fill('username', 'jefe-sc')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/staffing/change-requests?solicitud='.$request->id)
        ->assertSeeIn('[data-testid="request-impact"]', 'Impacto y alternativas')
        ->assertSeeIn('[data-testid="approve-blocked"]', 'No se puede aprobar')
        ->click('[data-testid="search-alternatives"]')
        ->waitForText('Alternativa A')
        ->assertSeeIn('[data-testid="alternatives-tabs"]', 'Alternativa A')
        ->assertSeeIn('[data-testid="alternative-panel"]', 'Cadena de movimientos')
        ->assertSee('Aprobar con la alternativa A')
        ->fill('[data-testid="decision-comment"]', 'Va la A: H cambia de salón.')
        ->click('[data-testid="approve-alternative"]')
        ->waitForText('Aprobaste la solicitud con la alternativa A')
        ->assertSeeIn('[data-testid="request-chosen"]', 'Lo aprobado · alternativa A')
        ->assertSeeIn('[data-testid="load-status"]', 'Ajustes')
        ->assertNoJavaScriptErrors();

    $request->refresh();

    expect($request->status)->toBe(ChangeRequestStatus::Approved)
        ->and($request->alternatives['chosen']['id'])->toBe($request->alternatives['items'][0]['id'])
        ->and($request->decision_comment)->toBe('Va la A: H cambia de salón.')
        ->and($w->load->fresh()->status)->toBe(LoadStatus::Adjusting);
});
