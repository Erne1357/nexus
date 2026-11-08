<?php

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Enums\Role;
use App\Modules\Staffing\Enums\ChangeRequestKind;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Enums\StaffingPermission;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Queries\MyChangeRequestsQuery;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/ChangeRequestScreensSupport.php';

/*
| «Mis solicitudes» y «Solicitar cambio» en Mi carga (tarea 3.11, maqueta C): el docente pide un cambio de horario o de
| salón en un grupo suyo con la carga del departamento en Asignación o Ajustes; una solicitud abierta por grupo
| (confirmado el 7 de noviembre de 2026).
*/

beforeEach(function () {
    $this->w = crWorld();
    $this->group = crGroup($this->w, '1A', 'Fundamentos de Programación', $this->w->sc, $this->w->isc, $this->w->teacher);
});

it('lista sus solicitudes con grupo, tipo, lo que propuso, estado, fecha y el comentario del jefe al rechazarla', function () {
    $other = crGroup($this->w, '3B', 'Estructura de Datos', $this->w->sc, $this->w->isc, $this->w->teacher);
    crRequest($this->group, $this->w->teacherUser, 'pending', ['proposed' => ['description' => 'Martes y jueves de 10 a 12'], 'created_at' => '2026-11-05 10:00']);
    crRequest($other, $this->w->teacherUser, 'rejected', ['kind' => 'room', 'proposed' => ['description' => 'Un laboratorio de cómputo'], 'decision_comment' => 'No hay laboratorio libre en ese horario.', 'created_at' => '2026-11-02 10:00']);
    crRequest(crGroup($this->w, '5A', 'Redes', $this->w->sc, $this->w->isc, $this->w->otherTeacher), $this->w->otherTeacherUser, 'pending', ['proposed' => ['description' => 'Solicitud de otro docente']]);

    Livewire::actingAs($this->w->teacherUser)->test('staffing::pages.my-load')
        ->assertSeeHtml('data-testid="my-requests"')
        ->assertSeeInOrder(['Mis solicitudes', 'Fundamentos de Programación', '1A', 'Horario', 'Martes y jueves de 10 a 12', 'Pendiente', '5 nov. 2026'])
        ->assertSeeInOrder(['Estructura de Datos', '3B', 'Salón', 'Un laboratorio de cómputo', 'Rechazada', 'No hay laboratorio libre en ese horario.', '2 nov. 2026'])
        ->assertDontSee('Solicitud de otro docente');
});

it('sin solicitudes dice que todavía no ha pedido cambios', function () {
    Livewire::actingAs($this->w->teacherUser)->test('staffing::pages.my-load')
        ->assertSee('Todavía no has pedido cambios en este periodo.');
});

it('abre el modal de un grupo y envía la solicitud de horario', function () {
    Livewire::actingAs($this->w->teacherUser)->test('staffing::pages.my-load')
        ->assertSeeHtml('data-testid="request-change-'.$this->group->id.'"')
        ->call('openRequest', $this->group->id)
        ->assertSet('showRequest', true)
        ->assertSee('Solicitar cambio')
        ->set('requestKind', 'schedule')
        ->set('proposal', 'Martes y jueves de 10:00 a 12:00')
        ->set('reason', 'Los lunes atiendo residencias en Campus I.')
        ->call('sendRequest')
        ->assertHasNoErrors()
        ->assertSet('showRequest', false);

    $request = ChangeRequest::query()->sole();
    expect($request->group_id)->toBe($this->group->id)
        ->and($request->kind)->toBe(ChangeRequestKind::Schedule)
        ->and($request->status)->toBe(ChangeRequestStatus::Pending)
        ->and($request->teacher_id)->toBe($this->w->teacher->id)
        ->and($request->proposed)->toBe(['description' => 'Martes y jueves de 10:00 a 12:00'])
        ->and($request->reason)->toBe('Los lunes atiendo residencias en Campus I.');
});

it('envía una solicitud de salón', function () {
    Livewire::actingAs($this->w->teacherUser)->test('staffing::pages.my-load')
        ->call('openRequest', $this->group->id)
        ->set('requestKind', 'room')
        ->set('proposal', 'Un laboratorio de cómputo')
        ->set('reason', 'Las prácticas necesitan equipo.')
        ->call('sendRequest')
        ->assertHasNoErrors();

    expect(ChangeRequest::query()->sole()->kind)->toBe(ChangeRequestKind::Room);
});

it('el motivo es obligatorio', function () {
    Livewire::actingAs($this->w->teacherUser)->test('staffing::pages.my-load')
        ->call('openRequest', $this->group->id)
        ->set('proposal', 'Martes de 10 a 12')
        ->set('reason', '   ')
        ->call('sendRequest')
        ->assertHasErrors(['reason' => 'required'])
        ->assertSet('showRequest', true)
        ->assertSee('Escribe el motivo del cambio.');

    expect(ChangeRequest::query()->count())->toBe(0);
});

it('lo que propone es obligatorio y el tipo debe ser horario o salón', function () {
    Livewire::actingAs($this->w->teacherUser)->test('staffing::pages.my-load')
        ->call('openRequest', $this->group->id)
        ->set('requestKind', 'otro')
        ->set('proposal', '')
        ->set('reason', 'Motivo')
        ->call('sendRequest')
        ->assertHasErrors(['proposal' => 'required', 'requestKind']);

    expect(ChangeRequest::query()->count())->toBe(0);
});

it('con una solicitud abierta en el grupo, el botón queda deshabilitado con la insignia de su estado', function () {
    crRequest($this->group, $this->w->teacherUser, 'approved');

    Livewire::actingAs($this->w->teacherUser)->test('staffing::pages.my-load')
        ->assertSeeHtml('data-testid="request-open-'.$this->group->id.'"')
        ->assertSeeHtml('data-testid="request-change-'.$this->group->id.'" disabled')
        ->assertSee('Ya tienes una solicitud abierta para este grupo.');
});

it('no abre el modal ni envía con una solicitud abierta en el grupo', function () {
    crRequest($this->group, $this->w->teacherUser, 'pending');

    Livewire::actingAs($this->w->teacherUser)->test('staffing::pages.my-load')
        ->call('openRequest', $this->group->id)
        ->assertSet('showRequest', false);

    expect(ChangeRequest::query()->count())->toBe(1);
});

it('tras una rechazada o aplicada puede pedir otra en el mismo grupo', function () {
    crRequest($this->group, $this->w->teacherUser, 'rejected');
    crRequest($this->group, $this->w->teacherUser, 'applied');

    Livewire::actingAs($this->w->teacherUser)->test('staffing::pages.my-load')
        ->assertDontSeeHtml('data-testid="request-change-'.$this->group->id.'" disabled')
        ->call('openRequest', $this->group->id)
        ->assertSet('showRequest', true);
});

it('con la carga fuera de Asignación y Ajustes no ofrece el botón y dice por qué', function (LoadStatus $status) {
    $this->w->scLoad->update(['status' => $status]);

    Livewire::actingAs($this->w->teacherUser)->test('staffing::pages.my-load')
        ->assertDontSeeHtml('data-testid="request-change-'.$this->group->id.'"')
        ->assertSee('La carga está en '.$status->label().': los cambios se piden en Asignación o Ajustes.')
        ->call('openRequest', $this->group->id)
        ->assertForbidden();
})->with([LoadStatus::Proposed, LoadStatus::Closed, LoadStatus::Published]);

it('con la carga en Ajustes sí ofrece el botón', function () {
    $this->w->scLoad->update(['status' => LoadStatus::Adjusting]);

    Livewire::actingAs($this->w->teacherUser)->test('staffing::pages.my-load')
        ->assertSeeHtml('data-testid="request-change-'.$this->group->id.'"');
});

it('sin el permiso de pedir no ve el botón ni puede abrir el modal', function () {
    app()->instance(PermissionRegistry::class, PermissionRegistry::fromDeclarations([StaffingPermission::class], [
        Role::Docente->value => [StaffingPermission::OfferView, StaffingPermission::MyLoadView],
    ], []));
    app()->forgetScopedInstances();

    Livewire::actingAs($this->w->teacherUser)->test('staffing::pages.my-load')
        ->assertDontSeeHtml('data-testid="request-groups"')
        ->assertDontSeeHtml('data-testid="request-change-'.$this->group->id.'"')
        ->call('openRequest', $this->group->id)
        ->assertForbidden();
});

it('un grupo que no es suyo no se puede pedir desde Mi carga', function () {
    $foreign = crGroup($this->w, '5A', 'Redes', $this->w->sc, $this->w->isc, $this->w->otherTeacher);

    Livewire::actingAs($this->w->teacherUser)->test('staffing::pages.my-load')
        ->assertDontSeeHtml('data-testid="request-change-'.$foreign->id.'"')
        ->call('openRequest', $foreign->id)
        ->assertNotFound();
});

it('la consulta de sus solicitudes hace un número constante de consultas', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(MyChangeRequestsQuery::class)->forTeacher($this->w->teacher, (string) $this->w->teacherUser->id, $this->w->term);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    crRequest($this->group, $this->w->teacherUser, 'pending');
    $few = $count();

    foreach (range(1, 6) as $n) {
        $group = crGroup($this->w, "G{$n}", "Materia {$n}", $n % 2 === 0 ? $this->w->sc : $this->w->cb, $this->w->isc, $this->w->teacher);
        crRequest($group, $this->w->teacherUser, $n % 2 === 0 ? 'rejected' : 'pending');
    }

    expect($count())->toBe($few)->toBeLessThanOrEqual(6);
});
