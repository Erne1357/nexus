<?php

use App\Modules\Core\Models\User;
use App\Modules\Staffing\Actions\ChangeRequests\AssessChangeImpact;
use Livewire\Livewire;

require_once __DIR__.'/ChangeRequestScreensSupport.php';

/*
| La sección «Impacto» del detalle de «Solicitudes de cambio» (CR-01, tarea 8.6): el jefe ve los choques que dejaría el
| cambio antes de decidir, con los grupos y docentes afectados; quien no ve la solicitud no ve su impacto.
*/

beforeEach(function () {
    $this->w = crWorld();
    $this->g1 = crGroup($this->w, '1A', 'Fundamentos de Programación', $this->w->sc, $this->w->isc, $this->w->teacher);
    $this->busy = crGroup($this->w, '3B', 'Cálculo Integral', $this->w->sc, $this->w->isc, $this->w->teacher, [[2, '07:00', '09:00']]);
});

function impactPage(User $user, string $id)
{
    return Livewire::withQueryParams(['solicitud' => $id])->actingAs($user)->test('staffing::pages.change-requests');
}

it('el jefe ve los choques del cambio agrupados por regla, con el grupo afectado y su docente', function () {
    $request = crRequest($this->g1, $this->w->teacherUser, 'pending', ['proposed' => ['description' => 'Pasar al martes', 'sessions' => [['day' => 2, 'starts_at' => '07:00', 'ends_at' => '09:00']]]]);
    app(AssessChangeImpact::class)->handle($request);

    impactPage($this->w->jefeSc, $request->id)
        ->assertSeeHtml('data-testid="request-impact"')
        ->assertSeeHtml('data-testid="impact-rule"')
        ->assertSee('HC-01')
        ->assertSee('Cálculo Integral')
        ->assertSee('Marisol Quiñones Duarte')
        ->assertSeeHtml('data-testid="recalculate-impact"');
});

it('sin choques dice «Sin choques»', function () {
    $request = crRequest($this->g1, $this->w->teacherUser, 'pending', ['proposed' => ['description' => 'Al viernes', 'sessions' => [['day' => 5, 'starts_at' => '07:00', 'ends_at' => '09:00']]]]);
    app(AssessChangeImpact::class)->handle($request);

    impactPage($this->w->jefeSc, $request->id)->assertSee('Sin choques')->assertDontSeeHtml('data-testid="impact-rule"');
});

it('el botón recalcula el impacto de la solicitud elegida', function () {
    $request = crRequest($this->g1, $this->w->teacherUser, 'pending', ['proposed' => ['description' => 'Pasar al martes', 'sessions' => [['day' => 2, 'starts_at' => '07:00', 'ends_at' => '09:00']]]]);

    impactPage($this->w->jefeSc, $request->id)->call('recalculateImpact')->assertSee('HC-01');

    expect($request->fresh()->impact['hard'])->not->toBe([]);
});

it('el jefe de otro departamento no ve el impacto ni puede recalcularlo', function () {
    $request = crRequest($this->g1, $this->w->teacherUser, 'pending', ['proposed' => ['description' => 'Pasar al martes', 'sessions' => [['day' => 2, 'starts_at' => '07:00', 'ends_at' => '09:00']]]]);
    app(AssessChangeImpact::class)->handle($request);

    impactPage($this->w->jefeCb, $request->id)->assertNotFound();
    Livewire::actingAs($this->w->jefeCb)->test('staffing::pages.change-requests')->assertDontSee('Cálculo Integral')->assertDontSeeHtml('data-testid="request-impact"');
});

it('el docente que la pidió ve el impacto pero no puede recalcularlo', function () {
    $request = crRequest($this->g1, $this->w->teacherUser, 'pending', ['proposed' => ['description' => 'Al viernes', 'sessions' => [['day' => 5, 'starts_at' => '07:00', 'ends_at' => '09:00']]]]);

    impactPage($this->w->teacherUser, $request->id)->assertDontSeeHtml('data-testid="recalculate-impact"')->call('recalculateImpact')->assertForbidden();
});
