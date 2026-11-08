<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| Sección de salón del cajón de la sesión (tarea 3.7; maqueta `cajones`, estado `#sesion`): el salón actual, los
| sugeridos con sus motivos y su estado, el buscador y «Asignar salón» o «Asignar y pedir préstamo». Asigna el jefe del
| departamento del grupo (`scheduling.room.assign`); el coordinador de la carrera dueña lo ve de solo lectura con el
| motivo; el jefe de otro departamento y otro campus no lo ven.
*/

const SESSION_ROOM = 'scheduling::components.session-room';

beforeEach(function () {
    $this->imparte = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->metal = Department::factory()->create(['code' => 'MM', 'name' => 'Metal-Mecánica']);
    $this->career = Career::factory()->create(['department_id' => $this->imparte->id]);
    $this->subject = Subject::factory()->create(['code' => 'AED-1026', 'department_id' => $this->imparte->id, 'needs_lab' => true]);
    $this->group = Group::factory()->create(['subject_id' => $this->subject->id, 'owner_career_id' => $this->career->id, 'code' => '3B', 'capacity' => 30]);
    $this->load = AcademicLoad::factory()->inStatus(LoadStatus::Staffing)->create([
        'term_id' => $this->group->term_id, 'campus_id' => $this->group->campus_id, 'department_id' => $this->imparte->id,
    ]);
    $this->session = GroupSession::factory()->for($this->group)->practica()->on(2, '15:00', '17:00')->create();
    $this->building = Building::factory()->create(['campus_id' => $this->group->campus_id]);
    $this->free = sessionRoomOf('CC-05', RoomType::SalaComputo, 32);
    $this->foreign = sessionRoomOf('MM-L2', RoomType::Laboratorio, 30, $this->metal);
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->imparte->id])->create();
});

function sessionRoomOf(string $code, RoomType $type, int $capacity, ?Department $owner = null): Room
{
    return Room::factory()->create([
        'building_id' => test()->building->id, 'code' => $code, 'type' => $type, 'capacity' => $capacity, 'department_id' => $owner?->id,
    ]);
}

function sessionRoomAs(User $user, ?GroupSession $session = null): Testable
{
    return Livewire::actingAs($user)->test(SESSION_ROOM, ['sessionId' => ($session ?? test()->session)->id]);
}

it('el jefe del departamento ve el salón actual, los sugeridos con sus motivos y asigna uno libre', function () {
    $component = sessionRoomAs($this->jefe)
        ->assertOk()
        ->assertSee('Salón actual')
        ->assertSee('Sin salón')
        ->assertSee('Salones sugeridos')
        ->assertSee('CC-05')
        ->assertSee('Cabe: 30 de 32')
        ->assertSee('Libre')
        ->assertDontSee('Lo asigna el jefe')
        ->set('roomId', $this->free->id)
        ->assertSee('Asignar salón')
        ->call('assign')
        ->assertHasNoErrors()
        ->assertDispatched('session-room-saved', sessionId: $this->session->id)
        ->assertSet('roomId', '')
        ->assertSee('Asignado');

    expect($this->session->fresh()->room_id)->toBe($this->free->id)
        ->and(lastToastOf($component))->toMatchArray(['type' => 'success', 'title' => 'Salón asignado', 'description' => 'CC-05']);
});

it('HC-10: un salón de otro departamento cambia el botón a «Asignar y pedir préstamo», lo asigna y pide el préstamo', function () {
    $component = sessionRoomAs($this->jefe)
        ->assertSee('De Metal-Mecánica · pide préstamo')
        ->set('roomId', $this->foreign->id)
        ->assertSee('Asignar y pedir préstamo')
        ->assertSee('Si eliges MM-L2, la sesión se guarda y queda marcada:')
        ->call('assign')
        ->assertHasNoErrors()
        ->assertSee('Préstamo pendiente');

    expect($this->session->fresh()->room_id)->toBe($this->foreign->id)
        ->and(InterdepartmentalAgreement::query()->sole()->status)->toBe(AgreementStatus::Proposed)
        ->and(lastToastOf($component))->toMatchArray(['title' => 'Salón asignado', 'description' => 'MM-L2 · préstamo pedido a Metal-Mecánica']);
});

it('HC-02: un salón ocupado se ve deshabilitado con quién lo ocupa y, pedido directo, da el mensaje del validador', function () {
    $busy = sessionRoomOf('CC-03', RoomType::SalaComputo, 30);
    $other = Group::factory()->create(['term_id' => $this->group->term_id, 'campus_id' => $this->group->campus_id, 'subject_id' => $this->subject->id, 'code' => '3A']);
    GroupSession::factory()->for($other)->inRoom($busy)->on(2, '15:00', '16:00')->create();

    sessionRoomAs($this->jefe)
        ->assertSee('Ocupado · AED-1026 3A · mar 15:00–16:00')
        ->assertSee('HC-02: no se puede elegir.')
        ->set('roomId', $busy->id)
        ->call('assign')
        ->assertHasErrors('session')
        ->assertSee('ya está ocupado');

    expect($this->session->fresh()->room_id)->toBeNull();
});

it('HC-05 y HC-06: muestra el choque que causaría cada salón y lo asigna marcado', function () {
    $aula = sessionRoomOf('LC-106', RoomType::Aula, 25);

    sessionRoomAs($this->jefe)
        ->assertSee('Aula: no sirve para práctica')
        ->assertSee('No cabe')
        ->set('roomId', $aula->id)
        ->assertSee('LC-106 es aula: las prácticas de AED-1026 piden laboratorio, taller o sala de cómputo.')
        ->call('assign')
        ->assertHasNoErrors();

    expect($this->session->fresh()->room_id)->toBe($aula->id);
});

it('quita el salón de la sesión, cancela su préstamo y avisa al editor', function () {
    sessionRoomAs($this->jefe)->set('roomId', $this->foreign->id)->call('assign');

    $component = sessionRoomAs($this->jefe)
        ->assertSee('MM-L2')
        ->assertSee('Quitar salón')
        ->call('removeRoom')
        ->assertHasNoErrors()
        ->assertDispatched('session-room-saved', sessionId: $this->session->id)
        ->assertSee('Sin salón');

    expect($this->session->fresh()->room_id)->toBeNull()
        ->and(InterdepartmentalAgreement::query()->sole()->status)->toBe(AgreementStatus::Cancelled)
        ->and(lastToastOf($component))->toMatchArray(['title' => 'Salón quitado', 'description' => 'MM-L2']);
});

it('busca otro salón por clave entre los que no están sugeridos', function () {
    foreach (range(1, 8) as $index) {
        sessionRoomOf("CC-1{$index}", RoomType::SalaComputo, 30);
    }
    sessionRoomOf('LC-106', RoomType::Aula, 35);

    sessionRoomAs($this->jefe)
        ->assertDontSee('LC-106')
        ->set('search', 'lc-1')
        ->assertSee('LC-106')
        ->set('search', 'zzz')
        ->assertSee('Sin resultados para «zzz».')
        ->set('search', 'cc-11')
        ->assertSee('Sin resultados para «cc-11».');
});

it('con la carga fuera de propuesta, asignación o ajustes, el jefe lo ve de solo lectura con ese motivo', function () {
    $this->session->update(['room_id' => $this->free->id]);
    $this->load->update(['status' => LoadStatus::Closed]);

    sessionRoomAs($this->jefe)
        ->assertOk()
        ->assertSee('CC-05')
        ->assertSee('está en cerrada: no se editan horarios.')
        ->assertDontSee('Quitar salón')
        ->assertSeeHtml('data-testid="session-room-reason"');
});

it('quitar el salón limpia la elección y la búsqueda', function () {
    $this->session->update(['room_id' => $this->free->id]);

    sessionRoomAs($this->jefe)
        ->set('roomId', $this->foreign->id)
        ->set('search', 'mm')
        ->call('removeRoom')
        ->assertSet('roomId', '')
        ->assertSet('search', '');
});

it('pide elegir un salón antes de asignar', function () {
    sessionRoomAs($this->jefe)->call('assign')->assertHasErrors(['roomId' => 'required']);

    expect($this->session->fresh()->room_id)->toBeNull();
});

it('el coordinador de la carrera dueña lo ve de solo lectura con el motivo y no asigna ni quita', function () {
    $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->group->campus_id, 'career_id' => $this->career->id])->create();

    sessionRoomAs($coordinador)
        ->assertOk()
        ->assertSee('CC-05')
        ->assertSee('Lo asigna el jefe de Sistemas y Computación.')
        ->set('roomId', $this->free->id)
        ->call('assign')
        ->assertForbidden();

    sessionRoomAs($coordinador)->call('removeRoom')->assertForbidden();

    expect($this->session->fresh()->room_id)->toBeNull();
});

it('el jefe de otro departamento no ve la sesión', function () {
    $jefeOtro = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->metal->id])->create();

    sessionRoomAs($jefeOtro)->assertForbidden();
});

it('un jefe que pierde el permiso después de montar recibe 403 al asignar o quitar', function () {
    $jefeOtro = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->metal->id])->create();
    $assigning = sessionRoomAs($this->jefe)->set('roomId', $this->free->id);
    $removing = sessionRoomAs($this->jefe);

    $this->actingAs($jefeOtro);

    $assigning->call('assign')->assertForbidden();
    $removing->call('removeRoom')->assertForbidden();
    expect($this->session->fresh()->room_id)->toBeNull();
});

it('el coordinador de la carrera en otro campus no ve la sesión', function () {
    $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => Campus::factory()->create()->id, 'career_id' => $this->career->id])->create();

    sessionRoomAs($coordinador)->assertForbidden();
});

it('un salón que no existe no llega a la base', function () {
    sessionRoomAs($this->jefe)->set('roomId', 'no-es-uuid')->call('assign')->assertNotFound();
});
