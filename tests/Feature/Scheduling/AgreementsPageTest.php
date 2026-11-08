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

    AcademicLoad::factory()->inStatus(LoadStatus::Adjusting)->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'department_id' => $this->ii->id]);
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

    it('HC-02: un préstamo que empalma con otro grupo no se puede aceptar; el motivo va escrito y la Action lo rechaza', function () {
        $this->session->delete();
        $other = Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'subject_id' => Subject::factory()->create(['code' => 'ACF-0901'])->id, 'code' => '1B']);
        GroupSession::factory()->for($other)->on(1, '10:00', '12:00')->inRoom($this->room)->create();

        agreementsAs($this->jefeSc)
            ->call('pick', $this->loan->id)
            ->assertSee('No se puede aceptar.')
            ->assertSee('LC-201 ya está ocupado el lunes de 10:00 a 12:00 por ACF-0901 1B (HC-02).')
            ->assertSee('Empalma')
            ->call('accept', $this->loan->id)
            ->assertHasErrors(['room_id']);

        expect($this->loan->fresh()->status)->toBe(AgreementStatus::Proposed);
    });

    it('HC-11: un bloqueo del salón también impide aceptar y la pantalla cita HC-11', function () {
        RoomBlock::factory()->create(['room_id' => $this->room->id, 'term_id' => $this->term->id, 'day' => 1, 'starts_at' => '10:00', 'ends_at' => '12:00', 'reason' => 'Mantenimiento']);

        agreementsAs($this->jefeSc)
            ->call('pick', $this->loan->id)
            ->assertSee('LC-201 ya está ocupado el lunes de 10:00 a 12:00 por Mantenimiento (HC-11).')
            ->call('accept', $this->loan->id)
            ->assertHasErrors(['room_id']);

        expect($this->loan->fresh()->status)->toBe(AgreementStatus::Proposed);
    });

    it('abre con ?acuerdo= el que llega en el aviso, y uno de otro departamento es 404', function () {
        agreementsAs($this->jefeSc, ['acuerdo' => $this->share->id])
            ->assertSet('mobileDetail', true)
            ->assertSee('Cupo de')
            ->assertSee('Si aceptas, se abre el cupo de ISC en SCC-1013 3A con 8 lugares.');

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

describe('decidir uno tras otro', function () {
    it('«Rechazar…» pone el motivo primero, antes de los datos y de la semana del salón', function () {
        agreementsAs($this->jefeSc)
            ->call('pick', $this->loan->id)
            ->call('confirmReject', $this->loan->id)
            ->assertSeeHtmlInOrder(['data-testid="agreement-reject-reason"', 'data-testid="agreement-comment"', 'data-testid="agreement-facts"', 'data-testid="room-week"']);
    });

    it('al aceptar o rechazar abre el siguiente por decidir de la lista; del último vuelve al primero y sin más se queda en el decidido', function () {
        $third = InterdepartmentalAgreement::factory()->sharedGroup(2)->create([
            'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'group_id' => $this->group->id,
            'from_department_id' => $this->ii->id, 'to_department_id' => $this->sc->id, 'created_at' => now()->addMinute(),
        ]);

        agreementsAs($this->jefeSc)
            ->call('pick', $this->share->id)
            ->call('accept', $this->share->id)
            ->assertSet('agreementId', $third->id)
            ->assertSet('mobileDetail', true)
            ->assertDispatched('agreement-shown')
            ->call('confirmReject', $third->id)
            ->set('comment', 'Sin lugares.')
            ->call('reject', $third->id)
            ->assertSet('agreementId', $this->loan->id)
            ->assertSee('Préstamo de')
            ->call('accept', $this->loan->id)
            ->assertSet('agreementId', $this->loan->id)
            ->assertSet('mobileDetail', false)
            ->assertSee('Aceptado por')
            ->assertSee('No tienes acuerdos por decidir.');
    });

    it('la lista nombra al otro departamento y deja la clave en segundo plano', function () {
        agreementsAs($this->jefeSc)
            ->assertSee('De Ingeniería Industrial')
            ->assertDontSeeHtml('>II → SC<');

        agreementsAs($this->jefeIi)->assertSee('Para Sistemas y Computación');
    });

    it('el grupo compartido muestra la semana del grupo, su semestre y plan, y dice la consecuencia una sola vez', function () {
        $component = agreementsAs($this->jefeSc)
            ->call('pick', $this->share->id)
            ->assertSeeHtml('data-testid="group-week"')
            ->assertSee('9:00–11:00')
            ->assertSee('LC-201')
            ->assertSee('Semestre 1 de '.$this->group->ownerCareer->code)
            ->assertSee($this->group->studyPlan->code)
            ->assertDontSeeHtml('data-testid="room-week"');

        expect(substr_count($component->html(), 'se abre el cupo'))->toBe(1);
    });
});

describe('jefe del departamento que pidió', function () {
    it('ve «Enviados», no decide y cancela su solicitud', function () {
        agreementsAs($this->jefeIi)
            ->assertSee('Enviados')
            ->assertSeeHtml('data-testid="agreement-cancel"')
            ->assertSee('Lo decide el jefe de Sistemas y Computación; todavía no responde.')
            ->assertSeeHtml('data-testid="agreement-accept"')
            ->call('accept', $this->loan->id)->assertForbidden();

        agreementsAs($this->jefeIi)->call('confirmReject', $this->loan->id)->assertForbidden();
        agreementsAs($this->jefeIi)->call('cancel', $this->loan->id)->assertHasNoErrors();

        expect($this->loan->fresh()->status)->toBe(AgreementStatus::Cancelled)
            ->and($this->loan->fresh()->cancelled_by)->toBe($this->jefeIi->id);
    });
});

describe('cancelar un préstamo', function () {
    it('quita el salón de la sesión cubierta y avisa en el toast; en un grupo compartido solo cancela', function () {
        agreementsAs($this->jefeIi)->call('cancel', $this->loan->id)->assertHasNoErrors();

        expect($this->session->fresh()->room_id)->toBeNull();

        agreementsAs($this->jefeIi)->call('cancel', $this->share->id)->assertHasNoErrors();

        expect($this->share->fresh()->status)->toBe(AgreementStatus::Cancelled);
    });
});

describe('negativas por id', function () {
    it('un jefe de un tercer departamento no encuentra el acuerdo II→SC: aceptar y rechazar por id dan 404', function () {
        $third = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->cb->id])->create();

        agreementsAs($third)->call('accept', $this->loan->id)->assertNotFound();
        agreementsAs($third)->set('comment', 'No.')->call('reject', $this->loan->id)->assertNotFound();
        agreementsAs($third)->call('cancel', $this->loan->id)->assertNotFound();

        expect($this->loan->fresh()->status)->toBe(AgreementStatus::Proposed);
    });

    it('rechazar directo lo prohíbe a quien pidió y a la Subdirección, y no cambia nada', function () {
        $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

        foreach ([$this->jefeIi, $subdireccion] as $user) {
            agreementsAs($user)->set('comment', 'No.')->call('reject', $this->loan->id)->assertForbidden();
        }

        expect($this->loan->fresh()->status)->toBe(AgreementStatus::Proposed)
            ->and($this->session->fresh()->room_id)->toBe($this->room->id);
    });

    it('cancelar uno ya aceptado o rechazado no hace nada: dice que ya se decidió', function () {
        $this->loan->update(['status' => AgreementStatus::Accepted, 'decided_by' => $this->jefeSc->id, 'decided_at' => now()]);
        $this->share->update(['status' => AgreementStatus::Rejected, 'comment' => 'No.', 'decided_by' => $this->jefeSc->id, 'decided_at' => now()]);

        foreach ([$this->loan, $this->share] as $agreement) {
            agreementsAs($this->jefeIi)->call('cancel', $agreement->id);
            expect($agreement->fresh()->status)->not->toBe(AgreementStatus::Cancelled);
        }
    });

    it('los toasts escapan las claves: una materia con HTML no se pinta como HTML', function () {
        $this->group->subject->update(['code' => '<b>X</b>']);
        $component = agreementsAs($this->jefeSc)->call('accept', $this->share->id);

        expect(json_encode($component->effects['xjs'] ?? []))->not->toContain('<b>X</b>');
    });
});

describe('subdirección', function () {
    it('ve los acuerdos del campus sin poder decidir ni cancelar', function () {
        $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

        agreementsAs($subdireccion)
            ->assertOk()
            ->assertSee('LC-201 · Lun 9:00–11:00')
            ->assertSee('Solo ves este acuerdo: lo decide el jefe de Sistemas y Computación.')
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

        foreach ([$coordinador, $docente] as $user) {
            foreach (['pick', 'accept', 'confirmReject', 'reject', 'cancel'] as $method) {
                $component = agreementsAs($this->jefeSc);
                $this->actingAs($user);
                app()->forgetScopedInstances();

                $component->call($method, $this->loan->id)->assertForbidden();
            }
        }

        expect($this->loan->fresh()->status)->toBe(AgreementStatus::Proposed);
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
