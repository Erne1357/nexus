<?php

use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Staffing\Actions\ChangeRequests\ApproveChangeRequest;
use App\Modules\Staffing\Actions\ChangeRequests\ChooseChangeAlternative;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Models\ChangeRequest;
use Livewire\Livewire;

require_once __DIR__.'/ChangeAlternativesSupport.php';

/*
| «Impacto y alternativas» en el detalle de «Solicitudes de cambio» (tarea 8.7, maqueta A «Pestañas», elegida por el
| usuario el 18 de noviembre de 2026): «Lo pedido» y una pestaña por alternativa del motor; el pie aprueba la pestaña
| abierta (CR-01 bloquea lo pedido con choques duros; CR-03 aprueba con la alternativa) y, ya aprobada, quien aplica
| escribe la alternativa (CR-04) o la abre en el editor.
*/

beforeEach(function () {
    $this->w = altWorld();
});

function altPage(User $user, ChangeRequest $request)
{
    return Livewire::withQueryParams(['solicitud' => $request->id])->actingAs($user)->test('staffing::pages.change-requests');
}

it('sin buscar: el jefe ve «Lo pedido» con sus choques, «Buscar alternativas» y «Aprobar» deshabilitado (CR-01)', function () {
    $request = altRequest($this->w);

    altPage($this->w->jefe, $request)
        ->assertSeeHtml('data-testid="alternatives-tabs"')
        ->assertSeeHtml('data-testid="tab-pedido"')
        ->assertSee('Lo pedido')
        ->assertSee('HC-02')
        ->assertSeeHtml('data-testid="search-alternatives"')
        ->assertDontSee('Alternativa A')
        ->assertSeeHtml('data-testid="approve-blocked"')
        ->assertSee('Recházala o busca alternativas.');
});

it('buscar trae las alternativas como pestañas A, B y C, con su veredicto y la cadena de movimientos', function () {
    $request = altRequest($this->w);

    $page = altPage($this->w->jefe, $request)->call('searchAlternatives')->assertHasNoErrors();

    $page->assertSeeHtml('data-testid="tab-'.altId($request->fresh()).'"')
        ->assertSee('Alternativa A')
        ->assertSee('Alternativa B')
        ->assertSee('Sin choques duros')
        ->assertSee('Se mueve para hacerle lugar')
        ->assertSee('Investigación de Operaciones')
        ->assertSeeHtml('data-testid="alternative-week"')
        ->assertSeeHtml('data-testid="approve-alternative"')
        ->assertSee('Aprobar con la alternativa A');

    expect($request->fresh()->alternatives['items'])->not->toBe([]);
});

it('la pestaña abierta decide qué se aprueba: en «Lo pedido» se aprueba lo pedido, bloqueado con choques duros', function () {
    $request = altProposed($this->w);

    altPage($this->w->jefe, $request)
        ->set('alternative', 'pedido')
        ->assertSee('Aprobar lo pedido')
        ->assertSee('No se puede aprobar lo pedido: deja 4 choques duros. Elige una alternativa o recházala.')
        ->call('approve')
        ->assertHasErrors('status');

    expect($request->fresh()->status)->toBe(ChangeRequestStatus::Pending);
});

it('CR-03: «Aprobar con la alternativa A» la elige, aprueba la solicitud y avisa', function () {
    $request = altProposed($this->w);
    $chain = altId($request);

    altPage($this->w->jefe, $request)
        ->set('alternative', $chain)
        ->set('comment', 'Va la cadena')
        ->call('approveWithAlternative')
        ->assertHasNoErrors()
        ->assertSee('Lo aprobado · alternativa A')
        ->assertSee('Aprobada · alternativa A');

    expect($request->fresh()->status)->toBe(ChangeRequestStatus::Approved)
        ->and($request->fresh()->alternatives['chosen']['id'])->toBe($chain)
        ->and($request->fresh()->decision_comment)->toBe('Va la cadena');
});

it('«Esto cambió…»: si el horario cambió, no aprueba, marca las alternativas vencidas y ofrece buscar otra vez', function () {
    $request = altProposed($this->w);
    GroupSession::query()->where('group_id', $this->w->other->id)->where('day', 4)->update(['starts_at' => '12:00', 'ends_at' => '13:00']);

    $page = altPage($this->w->jefe, $request)
        ->set('alternative', altId($request))
        ->call('approveWithAlternative')
        ->assertSeeHtml('data-testid="alternatives-stale"')
        ->assertSee('Esto cambió desde que se calcularon las alternativas')
        ->assertSee('Vencida')
        ->assertSeeHtml('data-testid="search-again"');

    expect($request->fresh()->status)->toBe(ChangeRequestStatus::Pending);

    $page->call('searchAlternatives')->assertDontSeeHtml('data-testid="alternatives-stale"');
});

it('sin alternativa: dice que el motor no encontró un acomodo sin choques duros', function () {
    $request = altRequest($this->w);
    $request->forceFill(['alternatives' => ['status' => 'none', 'calculation_id' => 'c1', 'requested_at' => now()->toIso8601String(), 'computed_at' => now()->toIso8601String(), 'engine' => 'php-heuristic', 'items' => [], 'message' => 'El motor no encontró acomodos sin choques.', 'chosen' => null]])->save();

    altPage($this->w->jefe, $request)
        ->assertSeeHtml('data-testid="alternatives-none"')
        ->assertSee('El motor no encontró un acomodo sin choques duros.')
        ->assertSee('Sin alternativa');
});

it('buscando: mientras el motor corre en la cola la pantalla pregunta cada 2 segundos, y un cálculo colgado sale como fallido', function () {
    $request = altRequest($this->w);
    $request->forceFill(['alternatives' => ['status' => 'computing', 'calculation_id' => 'c1', 'requested_at' => now()->toIso8601String(), 'requested_by' => $this->w->jefe->id]])->save();

    altPage($this->w->jefe, $request)
        ->assertSeeHtml('data-testid="alternatives-computing"')
        ->assertSeeHtml('wire:poll.2s="pollAlternatives"')
        ->assertSee('Buscando alternativas…')
        ->assertDontSeeHtml('data-testid="search-alternatives"');

    $request->forceFill(['alternatives' => [...$request->alternatives, 'requested_at' => now()->subHour()->toIso8601String()]])->save();

    altPage($this->w->jefe, $request)
        ->assertDontSeeHtml('wire:poll.2s="pollAlternatives"')
        ->assertSeeHtml('data-testid="alternatives-failed"')
        ->assertSee('vuelve a pedirlas');
});

it('solo avisos: lo pedido sin choques duros se aprueba como se pidió y no se buscan alternativas', function () {
    $request = altRequest($this->w, '11:00');

    altPage($this->w->jefe, $request)
        ->assertSee('Sin choques duros')
        ->assertSeeHtml('data-testid="search-alternatives-disabled"')
        ->assertDontSeeHtml('data-testid="approve-blocked"')
        ->call('approve')
        ->assertHasNoErrors();

    expect($request->fresh()->status)->toBe(ChangeRequestStatus::Approved);
});

it('el coordinador ve las pestañas de solo lectura: sin buscar ni aprobar', function () {
    $request = altProposed($this->w);

    altPage($this->w->coordIsc, $request)
        ->assertSeeHtml('data-testid="tab-'.altId($request).'"')
        ->assertDontSeeHtml('data-testid="search-alternatives"')
        ->assertDontSeeHtml('data-testid="approve-alternative"')
        ->call('searchAlternatives')
        ->assertForbidden();
});

it('aprobada con una alternativa, la coordinación la aplica de una vez y la solicitud queda aplicada', function () {
    $request = altProposed($this->w);
    app(ChooseChangeAlternative::class)->handle($request, $this->w->jefe, altId($request));

    altPage($this->w->coordIsc, $request)
        ->assertSee('Lo aprobado · alternativa A')
        ->assertSeeHtml('data-testid="apply-alternative"')
        ->assertSeeHtml('data-testid="open-editor"')
        ->assertSee('solicitud='.$request->id, false)
        ->call('applyAlternative')
        ->assertHasNoErrors();

    expect($request->fresh()->status)->toBe(ChangeRequestStatus::Applied)
        ->and(altLiveStarts($this->w->group))->toBe(['09:00']);
});

it('aprobada sin alternativa, el pie es el de siempre: abrir en el editor y marcar como aplicada', function () {
    $request = app(ApproveChangeRequest::class)->handle(altRequest($this->w, '11:00'), $this->w->jefe);

    altPage($this->w->coordIsc, $request)
        ->assertDontSeeHtml('data-testid="apply-alternative"')
        ->assertSeeHtml('data-testid="apply-request"');
});

it('alcance: el jefe de otro departamento no ve la solicitud, y el docente que la pidió no busca, no aprueba ni aplica', function () {
    $request = altProposed($this->w);

    altPage($this->w->jefeCb, $request)->assertNotFound();

    altPage($this->w->teacherUser, $request)
        ->assertDontSeeHtml('data-testid="search-alternatives"')
        ->call('searchAlternatives')->assertForbidden();
    altPage($this->w->teacherUser, $request)->set('alternative', altId($request))->call('approveWithAlternative')->assertForbidden();

    app(ChooseChangeAlternative::class)->handle($request, $this->w->jefe, altId($request));
    altPage($this->w->teacherUser, $request)->assertDontSeeHtml('data-testid="apply-alternative"')->call('applyAlternative')->assertForbidden();

    expect($request->fresh()->status)->toBe(ChangeRequestStatus::Approved);
});

it('«Abrir en el editor» lleva al grupo con la alternativa elegida por colocar', function () {
    $request = altProposed($this->w);
    app(ChooseChangeAlternative::class)->handle($request, $this->w->jefe, altId($request));

    Livewire::withQueryParams(['grupo' => $this->w->group->id, 'solicitud' => $request->id, 'modalidad' => 'PRES', 'campus' => 'C2'])
        ->actingAs($this->w->coordIsc)
        ->test('scheduling::pages.schedule-editor')
        ->assertSeeHtml('data-testid="pending-placements"')
        ->assertSee('Por colocar')
        ->assertSee('Alternativa A')
        ->assertSee('Investigación de Operaciones');

    Livewire::withQueryParams(['grupo' => $this->w->group->id, 'solicitud' => $request->id, 'modalidad' => 'PRES', 'campus' => 'C2'])
        ->actingAs($this->w->jefeCb)
        ->test('scheduling::pages.schedule-editor')
        ->assertDontSeeHtml('data-testid="pending-placements"');
});
