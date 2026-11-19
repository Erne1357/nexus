<?php

use App\Modules\Core\Models\User;
use App\Modules\Staffing\Actions\ChangeRequests\ApproveChangeRequest;
use App\Modules\Staffing\Actions\ChangeRequests\AssessChangeImpact;
use App\Modules\Staffing\Actions\ChangeRequests\RejectChangeRequest;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Models\ChangeRequest;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

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
    $request = crRequest($this->g1, $this->w->teacherUser, 'pending', ['proposed' => ['description' => 'Pasar al martes', 'sessions' => [['day' => 2, 'starts_at' => '07:00', 'ends_at' => '09:00'], ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '09:00']]]]);
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
    $request = crRequest($this->g1, $this->w->teacherUser, 'pending', ['proposed' => ['description' => 'Al viernes', 'sessions' => [['day' => 5, 'starts_at' => '07:00', 'ends_at' => '09:00'], ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '09:00']]]]);
    app(AssessChangeImpact::class)->handle($request);

    impactPage($this->w->jefeSc, $request->id)->assertSee('Sin choques')->assertDontSeeHtml('data-testid="impact-rule"');
});

it('el botón recalcula el impacto de la solicitud elegida', function () {
    $request = crRequest($this->g1, $this->w->teacherUser, 'pending', ['proposed' => ['description' => 'Pasar al martes', 'sessions' => [['day' => 2, 'starts_at' => '07:00', 'ends_at' => '09:00'], ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '09:00']]]]);

    impactPage($this->w->jefeSc, $request->id)->call('recalculateImpact')->assertSee('HC-01');

    expect($request->fresh()->impact['hard'])->not->toBe([]);
});

it('el jefe de otro departamento no ve el impacto ni puede recalcularlo', function () {
    $request = crRequest($this->g1, $this->w->teacherUser, 'pending', ['proposed' => ['description' => 'Pasar al martes', 'sessions' => [['day' => 2, 'starts_at' => '07:00', 'ends_at' => '09:00'], ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '09:00']]]]);
    app(AssessChangeImpact::class)->handle($request);

    impactPage($this->w->jefeCb, $request->id)->assertNotFound();
    Livewire::actingAs($this->w->jefeCb)->test('staffing::pages.change-requests')->assertDontSee('Cálculo Integral')->assertDontSeeHtml('data-testid="request-impact"');
});

it('el docente que la pidió ve el impacto pero no puede recalcularlo', function () {
    $request = crRequest($this->g1, $this->w->teacherUser, 'pending', ['proposed' => ['description' => 'Al viernes', 'sessions' => [['day' => 5, 'starts_at' => '07:00', 'ends_at' => '09:00'], ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '09:00']]]]);

    impactPage($this->w->teacherUser, $request->id)->assertDontSeeHtml('data-testid="recalculate-impact"')->call('recalculateImpact')->assertForbidden();
});

it('aprobar un cambio con choques duros se bloquea y la solicitud sigue pendiente', function () {
    $request = crRequest($this->g1, $this->w->teacherUser, 'pending', ['proposed' => ['description' => 'Pasar al martes', 'sessions' => [['day' => 2, 'starts_at' => '07:00', 'ends_at' => '09:00'], ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '09:00']]]]);

    expect(fn () => app(ApproveChangeRequest::class)->handle($request, $this->w->jefeSc))
        ->toThrow(ValidationException::class, 'No se puede aprobar: el cambio deja 1 choque duro.');
    expect($request->fresh()->status)->toBe(ChangeRequestStatus::Pending);
});

it('rechazar un cambio con choques duros sí se puede', function () {
    $request = crRequest($this->g1, $this->w->teacherUser, 'pending', ['proposed' => ['description' => 'Pasar al martes', 'sessions' => [['day' => 2, 'starts_at' => '07:00', 'ends_at' => '09:00'], ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '09:00']]]]);

    app(RejectChangeRequest::class)->handle($request, $this->w->jefeSc, 'Choca con otro grupo');

    expect($request->fresh()->status)->toBe(ChangeRequestStatus::Rejected);
});

it('aprobar un cambio sin choques o uno que no se puede simular funciona', function () {
    $clear = crRequest($this->g1, $this->w->teacherUser, 'pending', ['proposed' => ['description' => 'Al viernes', 'sessions' => [['day' => 5, 'starts_at' => '07:00', 'ends_at' => '09:00'], ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '09:00']]]]);
    $text = crRequest($this->busy, $this->w->teacherUser, 'pending', ['teacher_id' => null]);

    app(ApproveChangeRequest::class)->handle($clear, $this->w->jefeSc);
    app(ApproveChangeRequest::class)->handle($text, $this->w->jefeSc);

    expect($clear->fresh()->status)->toBe(ChangeRequestStatus::Approved)->and($text->fresh()->status)->toBe(ChangeRequestStatus::Approved);
});

it('el botón «Aprobar» queda deshabilitado con su motivo cuando el impacto guardado trae choques duros', function () {
    $request = crRequest($this->g1, $this->w->teacherUser, 'pending', ['proposed' => ['description' => 'Pasar al martes', 'sessions' => [['day' => 2, 'starts_at' => '07:00', 'ends_at' => '09:00'], ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '09:00']]]]);
    app(AssessChangeImpact::class)->handle($request);

    impactPage($this->w->jefeSc, $request->id)
        ->assertSeeHtml('data-testid="approve-blocked"')
        ->assertSee('No se puede aprobar: el cambio deja 1 choque duro.')
        ->assertSeeHtml('data-testid="reject-request"')
        ->call('approve');
    expect($request->fresh()->status)->toBe(ChangeRequestStatus::Pending);
});

it('quien solo ve la solicitud la abre sin recalcular ni escribir nada', function () {
    $request = crRequest($this->g1, $this->w->teacherUser, 'pending', ['proposed' => ['description' => 'Pasar al martes', 'sessions' => [['day' => 2, 'starts_at' => '07:00', 'ends_at' => '09:00'], ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '09:00']]]]);

    Livewire::actingAs($this->w->teacherUser)->test('staffing::pages.change-requests')->call('pick', $request->id);

    expect($request->fresh()->impact)->toBeNull()
        ->and(Activity::query()->where('subject_id', $request->id)->where('event', 'impact_assessed')->exists())->toBeFalse();
});

it('el jefe que abre una pendiente la recalcula', function () {
    $request = crRequest($this->g1, $this->w->teacherUser, 'pending', ['proposed' => ['description' => 'Pasar al martes', 'sessions' => [['day' => 2, 'starts_at' => '07:00', 'ends_at' => '09:00'], ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '09:00']]]]);

    Livewire::actingAs($this->w->jefeSc)->test('staffing::pages.change-requests')->call('pick', $request->id);

    expect($request->fresh()->impact['assessed_at'])->not->toBeNull();
});

it('el modal de nueva solicitud trae las sesiones actuales del grupo y mover solo una no da HC-12', function () {
    $page = Livewire::actingAs($this->w->coordIsc)->test('staffing::pages.change-requests')
        ->call('openRequest')
        ->set('requestGroupId', $this->g1->id)
        ->assertSet('proposedSessions', [
            ['day' => '1', 'starts_at' => '07:00', 'ends_at' => '09:00'],
            ['day' => '3', 'starts_at' => '07:00', 'ends_at' => '09:00'],
        ])
        ->assertSee('todas las sesiones del grupo')
        ->set('proposedSessions.0.day', '5')
        ->set('proposal', 'Mover el lunes al viernes')
        ->set('reason', 'Empalme')
        ->call('sendRequest')
        ->assertHasNoErrors();

    $request = ChangeRequest::query()->where('group_id', $this->g1->id)->sole();

    expect($request->impact['assessable'])->toBeTrue()
        ->and(array_column($request->impact['hard'], 'rule'))->not->toContain('HC-12');
});
