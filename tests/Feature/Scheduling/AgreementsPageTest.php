<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\RoomBlock;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Models\GroupSession;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| Pantalla «Acuerdos» (tareas 3.6 y 3.7, maqueta B): el jefe del departamento dueño decide los préstamos y grupos
| compartidos que le piden; el que pidió los cancela; la Subdirección solo ve; coordinador y docente no entran; otro
| campus no ve nada. Cada método vuelve a autorizar.
*/

const AGREEMENTS = 'scheduling::pages.agreements';

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    TermOpening::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id]);
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->ii = Department::factory()->create(['code' => 'II', 'name' => 'Ingeniería Industrial']);
    $this->cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);

    $this->group = Group::factory()->withQuota(20)->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'code' => '3A', 'capacity' => 40,
        'subject_id' => Subject::factory()->create(['code' => 'SCC-1013', 'name' => 'Redes', 'department_id' => $this->ii->id])->id,
    ]);
    $this->room = Room::factory()->create([
        'building_id' => Building::factory()->create(['campus_id' => $this->campus->id])->id, 'department_id' => $this->sc->id, 'code' => 'LC-201',
    ]);
    $this->session = GroupSession::factory()->for($this->group)->on(1, '09:00', '11:00')->inRoom($this->room)->create();

    // II le pide LC-201 a SC, dueño del salón.
    $this->loan = InterdepartmentalAgreement::factory()->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'group_id' => $this->group->id, 'room_id' => $this->room->id,
        'day' => 1, 'starts_at' => '09:00', 'ends_at' => '11:00', 'from_department_id' => $this->ii->id, 'to_department_id' => $this->sc->id,
    ]);
    // II propone a SC un cupo de ISC en el mismo grupo.
    $this->career = Career::factory()->create(['code' => 'ISC', 'name' => 'Sistemas Computacionales', 'department_id' => $this->sc->id]);
    $this->share = InterdepartmentalAgreement::factory()->sharedGroup(8)->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'group_id' => $this->group->id,
        'from_department_id' => $this->ii->id, 'to_department_id' => $this->sc->id, 'career_id' => $this->career->id,
    ]);

    $this->jefeSc = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->sc->id])->create();
    $this->jefeIi = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->ii->id])->create();
});

function agreementsAs(User $user, array $query = []): Testable
{
    return Livewire::withQueryParams($query)->actingAs($user)->test(AGREEMENTS);
}

describe('jefe del departamento que decide', function () {
    it('ve «Por decidir» con los dos acuerdos, el detalle del primero y la semana del salón', function () {
        agreementsAs($this->jefeSc)
            ->assertOk()
            ->assertSee('Acuerdos')
            ->assertSee('Por decidir')
            ->assertSee('LC-201 · Lun 9:00–11:00')
            ->assertSee('SCC-1013 3A')
            ->assertSeeHtml('data-testid="room-week"')
            ->assertSeeHtml('data-testid="agreement-accept"')
            ->assertDontSeeHtml('data-testid="agreement-cancel"');
    });

    it('elige un préstamo, lo rechaza con motivo y la sesión se queda sin salón', function () {
        agreementsAs($this->jefeSc)
            ->call('pick', $this->loan->id)
            ->assertSee('Préstamo de')
            ->call('confirmReject', $this->loan->id)
            ->assertSee('Motivo del rechazo (obligatorio)')
            ->assertSee('la sesión de SCC-1013 3A del lunes de 9:00–11:00 se queda sin salón (LC-201)')
            ->set('comment', 'Se cruza con una práctica.')
            ->call('reject', $this->loan->id)
            ->assertHasNoErrors()
            ->assertSet('rejecting', false);

        expect($this->loan->fresh()->status)->toBe(AgreementStatus::Rejected)
            ->and($this->loan->fresh()->comment)->toBe('Se cruza con una práctica.')
            ->and($this->session->fresh()->room_id)->toBeNull();
    });

    it('rechazar sin motivo muestra el error y no rechaza', function () {
        agreementsAs($this->jefeSc)
            ->call('confirmReject', $this->loan->id)
            ->set('comment', '   ')
            ->call('reject', $this->loan->id)
            ->assertHasErrors(['comment' => 'required'])
            ->assertSee('Escribe por qué rechazas el acuerdo.')
            ->assertSet('rejecting', true);

        expect($this->loan->fresh()->status)->toBe(AgreementStatus::Proposed)
            ->and($this->session->fresh()->room_id)->toBe($this->room->id);
    });

    it('acepta un préstamo', function () {
        agreementsAs($this->jefeSc)->call('accept', $this->loan->id)->assertHasNoErrors();

        expect($this->loan->fresh()->status)->toBe(AgreementStatus::Accepted)
            ->and($this->loan->fresh()->decided_by)->toBe($this->jefeSc->id)
            ->and($this->session->fresh()->room_id)->toBe($this->room->id);
    });

    it('acepta un grupo compartido y se crea el cupo de la carrera; lo rechaza con motivo sin crearlo', function () {
        agreementsAs($this->jefeSc)->call('accept', $this->share->id)->assertHasNoErrors();

        expect($this->share->fresh()->status)->toBe(AgreementStatus::Accepted)
            ->and(GroupQuota::query()->where('group_id', $this->group->id)->where('career_id', $this->career->id)->value('max_seats'))->toBe(8);

        $other = InterdepartmentalAgreement::factory()->sharedGroup(3)->create([
            'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'group_id' => $this->group->id,
            'from_department_id' => $this->ii->id, 'to_department_id' => $this->sc->id,
        ]);

        agreementsAs($this->jefeSc)->call('confirmReject', $other->id)->set('comment', 'Sin lugares.')->call('reject', $other->id)->assertHasNoErrors();

        expect($other->fresh()->status)->toBe(AgreementStatus::Rejected)
            ->and(GroupQuota::query()->where('career_id', $other->career_id)->exists())->toBeFalse();
    });

    it('un préstamo que empalma con otro grupo no se puede aceptar: el motivo va escrito y deshabilitado', function () {
        RoomBlock::factory()->create(['room_id' => $this->room->id, 'term_id' => $this->term->id, 'day' => 1, 'starts_at' => '10:00', 'ends_at' => '12:00', 'reason' => 'Mantenimiento']);

        agreementsAs($this->jefeSc)
            ->call('pick', $this->loan->id)
            ->assertSee('No se puede aceptar.')
            ->assertSee('LC-201 ya está ocupado el lunes de 10:00 a 12:00 por Mantenimiento (HC-02).')
            ->assertSee('Empalma')
            ->call('accept', $this->loan->id);

        expect($this->loan->fresh()->status)->toBe(AgreementStatus::Proposed);
    });

    it('abre con ?acuerdo= el que llega en el aviso, y uno de otro departamento es 404', function () {
        agreementsAs($this->jefeSc, ['acuerdo' => $this->share->id])
            ->assertSet('mobileDetail', true)
            ->assertSee('Cupo de')
            ->assertSee('Lo acepta el jefe del departamento de ISC');

        $foreign = InterdepartmentalAgreement::factory()->create([
            'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'from_department_id' => $this->ii->id, 'to_department_id' => $this->cb->id,
        ]);

        agreementsAs($this->jefeSc, ['acuerdo' => $foreign->id])->assertNotFound();
    });

    it('filtra por tipo y por texto, y dice «Sin acuerdos aquí.» cuando no hay', function () {
        agreementsAs($this->jefeSc)
            ->set('kind', 'shared_group')
            ->assertDontSee('LC-201 · Lun')
            ->set('kind', '')
            ->set('search', 'no existe')
            ->assertSee('Sin acuerdos aquí.')
            ->assertDontSee('No tienes acuerdos por decidir.');
    });

    it('sin acuerdos por decidir dice «No tienes acuerdos por decidir.»', function () {
        InterdepartmentalAgreement::query()->update(['status' => AgreementStatus::Accepted, 'decided_by' => $this->jefeIi->id, 'decided_at' => now()]);

        agreementsAs($this->jefeSc)->assertSee('No tienes acuerdos por decidir.');
    });

    it('no puede cancelar lo que no pidió', function () {
        agreementsAs($this->jefeSc)->call('cancel', $this->loan->id)->assertForbidden();
    });
});

describe('jefe del departamento que pidió', function () {
    it('ve «Enviados», no decide y cancela su solicitud', function () {
        agreementsAs($this->jefeIi)
            ->assertSee('Enviados')
            ->assertSeeHtml('data-testid="agreement-cancel"')
            ->assertSee('Lo decide el jefe de SC; todavía no responde.')
            ->assertSeeHtml('data-testid="agreement-accept"')
            ->call('accept', $this->loan->id)->assertForbidden();

        agreementsAs($this->jefeIi)->call('confirmReject', $this->loan->id)->assertForbidden();
        agreementsAs($this->jefeIi)->call('cancel', $this->loan->id)->assertHasNoErrors();

        expect($this->loan->fresh()->status)->toBe(AgreementStatus::Cancelled)
            ->and($this->loan->fresh()->cancelled_by)->toBe($this->jefeIi->id);
    });
});

describe('subdirección', function () {
    it('ve los acuerdos del campus sin poder decidir ni cancelar', function () {
        $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

        agreementsAs($subdireccion)
            ->assertOk()
            ->assertSee('LC-201 · Lun 9:00–11:00')
            ->assertSee('Solo ves este acuerdo: lo decide el jefe de SC.')
            ->assertDontSeeHtml('data-testid="agreement-cancel"')
            ->call('accept', $this->loan->id)->assertForbidden();

        agreementsAs($subdireccion)->call('confirmReject', $this->loan->id)->assertForbidden();
        agreementsAs($subdireccion)->call('cancel', $this->loan->id)->assertForbidden();
        expect($this->loan->fresh()->status)->toBe(AgreementStatus::Proposed);
    });
});

describe('otros puestos', function () {
    it('el coordinador y el docente no entran y sus métodos dan 403', function () {
        $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->career->id])->create();
        $docente = User::factory()->withRole(Role::Docente)->create();

        agreementsAs($coordinador)->assertForbidden();
        agreementsAs($docente)->assertForbidden();
    });

    it('un jefe de otro campus no ve nada: ni la lista ni el acuerdo por ?acuerdo=', function () {
        $other = Campus::factory()->create();
        TermOpening::factory()->create(['term_id' => $this->term->id, 'campus_id' => $other->id]);
        vacate(Role::JefeDepartamento, $this->sc->id);
        $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $other->id, 'department_id' => $this->sc->id, 'valid_from' => today()->toDateString()])->create();

        agreementsAs($jefe)->assertOk()->assertDontSee('LC-201')->assertSee('No tienes acuerdos por decidir.');
        agreementsAs($jefe, ['acuerdo' => $this->loan->id])->assertNotFound();
        agreementsAs($jefe)->call('accept', $this->loan->id)->assertNotFound();
    });

    it('un id que no es de un acuerdo visible es 404', function () {
        agreementsAs($this->jefeSc)->call('pick', 'no-es-uuid')->assertNotFound();
    });
});

it('la ruta pide entrar y muestra la pantalla a quien ve acuerdos', function () {
    $this->get(route('scheduling.agreements'))->assertRedirect();
    $this->actingAs($this->jefeSc)->get(route('scheduling.agreements'))->assertOk()->assertSee('Acuerdos');
});
