<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\AgreementAccepted;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\RoomBlock;
use App\Modules\Scheduling\Actions\Agreements\CancelRoomLoan;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Notifications\LoanCancelled;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| Pantalla «Acuerdos» (tareas 3.6 y 3.7, maqueta B): el jefe del departamento dueño decide los préstamos y grupos
| compartidos que le piden; el que pidió los cancela; la Subdirección solo ve; coordinador y docente no entran; otro
| campus no ve nada. Cada método vuelve a autorizar.
*/

const AGREEMENTS = 'scheduling::pages.agreements';

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    TermOpening::factory()->create(['term_id' => $this->term->id, 'modality_id' => $this->campus->modality_id]);
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

    AcademicLoad::factory()->inStatus(LoadStatus::Adjusting)->create(['term_id' => $this->term->id, 'modality_id' => $this->campus->modality_id, 'department_id' => $this->ii->id]);
    $this->jefeSc = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->sc->id])->create();
    $this->jefeIi = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->ii->id])->create();
});

function agreementsAs(User $user, array $query = []): Testable
{
    return Livewire::withQueryParams($query)->actingAs($user)->test(AGREEMENTS);
}

it('el aviso de un préstamo cancelado abre el acuerdo cancelado al jefe del departamento dueño (confirmado el 8 de noviembre de 2026)', function () {
    app(CancelRoomLoan::class)->handle($this->loan, $this->jefeIi);
    $notice = NotificationMessage::fromArray($this->jefeSc->notifications()->where('type', LoanCancelled::class)->sole()->data);

    agreementsAs($this->jefeSc, $notice->parameters)
        ->assertOk()
        ->assertSet('agreementId', $this->loan->id)
        ->assertSee('Cancelado');
});

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

    it('el grupo compartido siempre dice los lugares y el dueño del grupo, aunque el grupo no tenga sesiones', function () {
        $this->session->delete();

        agreementsAs($this->jefeSc)
            ->call('pick', $this->share->id)
            ->assertSeeHtmlInOrder(['Lugares', '>8<', 'Dueño del grupo', 'Ingeniería Industrial'])
            ->assertSee('El grupo todavía no tiene sesiones.');
    });
});

describe('aceptar varios', function () {
    beforeEach(function () {
        Event::fake([AgreementAccepted::class]);
        // Un tercer acuerdo que rompe HC-07 en otro grupo del mismo departamento: 20 de la dueña + 30 > 40 de capacidad.
        // Va en su propio grupo porque las propuestas apartan sus lugares: en el grupo de ISC, también impediría aceptar ISC.
        $this->tooMany = InterdepartmentalAgreement::factory()->sharedGroup(30)->create([
            'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'from_department_id' => $this->ii->id, 'to_department_id' => $this->sc->id,
            'created_at' => now()->addMinute(),
            'group_id' => Group::factory()->withQuota(20)->create([
                'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'code' => '3B', 'capacity' => 40,
                'subject_id' => Subject::factory()->create(['department_id' => $this->ii->id])->id,
            ])->id,
        ]);
    });

    it('acepta préstamos y grupos compartidos juntos, cada uno con su bitácora y su aviso; el que falla no deshace los demás', function () {
        agreementsAs($this->jefeSc)
            ->set('selected', [$this->loan->id, $this->share->id, $this->tooMany->id])
            ->call('acceptMany')
            ->assertHasNoErrors()
            ->assertSet('selected', [$this->tooMany->id])
            ->assertSet('bulkResult.accepted', 2)
            ->assertSeeHtml('Aceptados <span class="font-mono">2</span>')
            ->assertSeeHtml('No se aceptaron <span class="font-mono">1</span>')
            ->assertSee('HC-07');

        expect($this->loan->fresh()->status)->toBe(AgreementStatus::Accepted)
            ->and($this->share->fresh()->status)->toBe(AgreementStatus::Accepted)
            ->and($this->tooMany->fresh()->status)->toBe(AgreementStatus::Proposed)
            ->and(GroupQuota::query()->where('group_id', $this->group->id)->where('career_id', $this->career->id)->value('max_seats'))->toBe(8)
            ->and(Activity::query()->where('event', 'accepted')->where('subject_type', (new InterdepartmentalAgreement)->getMorphClass())->pluck('subject_id')->all())
            ->toEqualCanonicalizing([$this->loan->id, $this->share->id]);

        Event::assertDispatchedTimes(AgreementAccepted::class, 2);
    });

    it('un id ajeno, de otro campus que el filtro deja fuera, que solo ve o que no es uuid no se acepta ni se nombra: «Ya no está disponible» y los demás sí', function () {
        $foreign = InterdepartmentalAgreement::factory()->create([
            'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'from_department_id' => $this->ii->id, 'to_department_id' => $this->cb->id,
        ]);
        $otherCampus = Campus::factory()->create();
        // El periodo ya está abierto en su modalidad, Presencial (M4).
        $elsewhere = InterdepartmentalAgreement::factory()->create([
            'term_id' => $this->term->id, 'campus_id' => $otherCampus->id, 'from_department_id' => $this->ii->id, 'to_department_id' => $this->sc->id,
        ]);
        $unavailable = ['label' => 'Un acuerdo', 'message' => 'Ya no está disponible.'];

        agreementsAs($this->jefeSc, ['campus' => 'C2'])
            ->set('selected', [$foreign->id, $this->share->id, 'no-es-uuid', $elsewhere->id])
            ->call('acceptMany')
            ->assertOk()
            ->assertSet('bulkResult', ['accepted' => 1, 'failed' => [$unavailable, $unavailable, $unavailable]])
            ->assertSet('selected', [])
            ->assertDontSee('Ciencias Básicas');

        agreementsAs($this->jefeIi)->set('selected', [$this->loan->id])->call('acceptMany')->assertOk()->assertSet('bulkResult', ['accepted' => 0, 'failed' => [$unavailable]]);

        expect($this->share->fresh()->status)->toBe(AgreementStatus::Accepted)
            ->and($foreign->fresh()->status)->toBe(AgreementStatus::Proposed)
            ->and($elsewhere->fresh()->status)->toBe(AgreementStatus::Proposed)
            ->and($this->loan->fresh()->status)->toBe(AgreementStatus::Proposed);
        Event::assertDispatchedTimes(AgreementAccepted::class, 1);
    });

    it('uno que otra sesión ya decidió va a los que no se aceptaron y sale de la selección', function () {
        $component = agreementsAs($this->jefeSc)->set('selected', [$this->share->id, $this->loan->id]);
        $this->loan->update(['status' => AgreementStatus::Rejected, 'comment' => 'No.', 'decided_by' => $this->jefeSc->id, 'decided_at' => now()]);

        $component->call('acceptMany')
            ->assertSet('bulkResult.accepted', 1)
            ->assertSet('bulkResult.failed.0.message', 'Este acuerdo ya se decidió.')
            ->assertSet('selected', []);

        expect($this->share->fresh()->status)->toBe(AgreementStatus::Accepted);
    });

    it('un error inesperado que no es de la base en uno se reporta, se dice sin detalle y los demás siguen', function () {
        Exceptions::fake();
        GroupQuota::creating(function (GroupQuota $quota): void {
            if ($quota->career_id === $this->career->id) {
                throw new RuntimeException('Falla simulada de la Action');
            }
        });

        agreementsAs($this->jefeSc)
            ->set('selected', [$this->share->id, $this->loan->id])
            ->call('acceptMany')
            ->assertOk()
            ->assertSet('bulkResult.accepted', 1)
            ->assertSet('bulkResult.failed.0.message', 'No se pudo aceptar por un error inesperado; intenta de nuevo.')
            ->assertDontSee('Falla simulada')
            ->assertSet('selected', [$this->share->id]);

        Exceptions::assertReported(RuntimeException::class);
        expect($this->loan->fresh()->status)->toBe(AgreementStatus::Accepted);
    });

    it('acepta hasta 50 a la vez: con más no acepta ninguno, y la lista lo dice cuando hay más de 50 por decidir', function () {
        $many = InterdepartmentalAgreement::factory()->count(49)->sharedGroup(1)->create([
            'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'group_id' => Group::factory()->withQuota(20)->create([
                'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'capacity' => 200,
            ])->id,
            'from_department_id' => $this->ii->id, 'to_department_id' => $this->sc->id,
        ]);

        agreementsAs($this->jefeSc)
            ->assertSee('Elegir los primeros 50 de 52 que puedes aceptar')
            ->assertSee('Se aceptan hasta 50 a la vez.')
            ->set('selected', [...$many->pluck('id')->all(), $this->share->id, $this->loan->id])
            ->call('acceptMany')
            ->assertHasErrors(['selected' => 'max'])
            ->assertSee('Acepta hasta 50 a la vez.');

        expect(InterdepartmentalAgreement::query()->where('status', AgreementStatus::Accepted)->count())->toBe(0);
    });

    it('acepta 50 (40 grupos compartidos y 10 préstamos) en una sola petición en poco tiempo', function () {
        $this->group->update(['capacity' => 200]);
        $shares = InterdepartmentalAgreement::factory()->count(40)->sharedGroup(1)->create([
            'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'group_id' => $this->group->id,
            'from_department_id' => $this->ii->id, 'to_department_id' => $this->sc->id,
        ]);
        $building = Building::factory()->create(['campus_id' => $this->campus->id]);
        $loans = collect(range(1, 10))->map(fn (int $i): InterdepartmentalAgreement => InterdepartmentalAgreement::factory()->create([
            'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'group_id' => $this->group->id,
            'room_id' => Room::factory()->create(['building_id' => $building->id, 'department_id' => $this->sc->id, 'code' => "LB-{$i}"])->id,
            'day' => 2, 'starts_at' => '07:00', 'ends_at' => '09:00', 'from_department_id' => $this->ii->id, 'to_department_id' => $this->sc->id,
        ]));
        $component = agreementsAs($this->jefeSc)->set('selected', [...$shares->pluck('id')->all(), ...$loans->pluck('id')->all()]);

        $started = microtime(true);
        $component->call('acceptMany')->assertSet('bulkResult.accepted', 50);
        $seconds = microtime(true) - $started;

        expect($seconds)->toBeLessThan(20.0);
    });

    it('HC-07: dos propuestas de antes de la regla que ya no caben juntas no se aceptan hasta retirar o rechazar una (supuesto por confirmar)', function () {
        // La dueña tiene 20 de 40 y las dos propuestas apartan 15 cada una: el grupo ya se pasa por 10.
        $older = InterdepartmentalAgreement::factory()->sharedGroup(15)->create([
            'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'group_id' => $this->group->id,
            'from_department_id' => $this->ii->id, 'to_department_id' => $this->sc->id, 'created_at' => now()->subMinutes(10),
        ]);
        $newer = InterdepartmentalAgreement::factory()->sharedGroup(15)->create([
            'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'group_id' => $this->group->id,
            'from_department_id' => $this->ii->id, 'to_department_id' => $this->sc->id, 'created_at' => now()->subMinutes(5),
        ]);
        $this->share->update(['status' => AgreementStatus::Rejected, 'comment' => 'No.', 'decided_by' => $this->jefeSc->id, 'decided_at' => now()]);

        agreementsAs($this->jefeSc)->set('selected', [$newer->id, $older->id])->call('acceptMany')->assertSet('bulkResult.accepted', 0);
        expect($older->fresh()->status)->toBe(AgreementStatus::Proposed)
            ->and($newer->fresh()->status)->toBe(AgreementStatus::Proposed);

        $newer->update(['status' => AgreementStatus::Rejected, 'comment' => 'No.', 'decided_by' => $this->jefeSc->id, 'decided_at' => now()]);
        agreementsAs($this->jefeSc)->set('selected', [$older->id])->call('acceptMany')->assertSet('bulkResult.accepted', 1);

        expect($older->fresh()->status)->toBe(AgreementStatus::Accepted);
    });

    it('un error de la base en uno no corta los demás: lo reporta, sigue y lo deja elegido', function () {
        GroupQuota::creating(function (GroupQuota $quota): void {
            if ($quota->career_id === $this->career->id) {
                throw new QueryException('pgsql', 'insert into "group_quotas"', [], new PDOException('SQLSTATE[XX000]: falla simulada'));
            }
        });

        agreementsAs($this->jefeSc)
            ->set('selected', [$this->share->id, $this->loan->id])
            ->call('acceptMany')
            ->assertOk()
            ->assertSet('bulkResult.accepted', 1)
            ->assertSet('bulkResult.failed.0.message', 'No se pudo aceptar por un error de la base de datos; intenta de nuevo.')
            ->assertSet('selected', [$this->share->id]);

        expect($this->loan->fresh()->status)->toBe(AgreementStatus::Accepted)
            ->and($this->share->fresh()->status)->toBe(AgreementStatus::Proposed);
    });

    it('el resultado se quita al decidir otro, al cancelar o al cambiar el filtro o la búsqueda', function () {
        $component = agreementsAs($this->jefeSc)
            ->set('selected', [$this->tooMany->id])
            ->call('acceptMany')
            ->assertSet('bulkResult.accepted', 0)
            ->call('accept', $this->loan->id)
            ->assertSet('bulkResult', null)
            ->assertDontSeeHtml('data-testid="bulk-result"');

        foreach ([fn ($page) => $page->set('search', 'SCC'), fn ($page) => $page->set('kind', 'shared_group')] as $change) {
            $change($component->set('selected', [$this->tooMany->id])->call('acceptMany')->assertNotSet('bulkResult', null))->assertSet('bulkResult', null);
        }

        $component->call('dismissBulkResult')->assertSet('bulkResult', null);
    });

    it('cada por decidir lleva su casilla, que se elige en el navegador; un préstamo que empalma va deshabilitado con su motivo', function () {
        $this->session->delete();
        $other = Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'subject_id' => Subject::factory()->create(['code' => 'ACF-0901'])->id, 'code' => '1B']);
        GroupSession::factory()->for($other)->on(1, '10:00', '12:00')->inRoom($this->room)->create();

        agreementsAs($this->jefeSc)
            ->assertSeeHtml('data-select="'.$this->share->id.'"')
            ->assertSeeHtml('data-select="'.$this->loan->id.'" disabled')
            ->assertSeeHtml('wire:model="selected"')
            ->assertDontSeeHtml('wire:model.live="selected"')
            ->assertSee('No se puede aceptar: ACF-0901 1B lo ocupa de 10:00 a 12:00 (HC-02).')
            ->assertSee('Elegir los 2 que puedes aceptar')
            ->assertSeeHtml('data-testid="bulk-accept"')
            ->set('kind', 'room_loan')
            ->assertSet('selected', []);
    });

    it('quien no decide no ve casillas ni la barra', function () {
        $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

        agreementsAs($subdireccion)->assertDontSeeHtml('data-select=')->assertDontSeeHtml('data-testid="bulk-bar"');
    });
});

describe('una sola lectura', function () {
    it('al decidir, la bandeja se lee una sola vez en la petición', function () {
        $component = agreementsAs($this->jefeSc);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $component->call('accept', $this->loan->id)->assertSet('agreementId', $this->share->id);

        $inboxReads = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn (string $sql): bool => str_starts_with($sql, 'select * from "interdepartmental_agreements" where "interdepartmental_agreements"."term_id"') && ! str_contains($sql, '"id" ='));
        DB::disableQueryLog();

        expect($inboxReads)->toHaveCount(1);
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

    it('MS6: el jefe con nombramiento en otro campus lee los acuerdos de toda la modalidad, pero no cancela el préstamo de un campus que no es el suyo', function () {
        $other = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
        vacate(Role::JefeDepartamento, $this->ii->id);
        $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $other->id, 'department_id' => $this->ii->id, 'valid_from' => today()->toDateString()])->create();

        agreementsAs($jefe, ['acuerdo' => $this->loan->id])->assertOk()->assertSee('LC-201')->assertDontSeeHtml('data-testid="agreement-cancel"');
        agreementsAs($jefe)->call('cancel', $this->loan->id)->assertForbidden();

        expect($this->loan->fresh()->status)->toBe(AgreementStatus::Proposed);
    });

    it('M12: el jefe decide los acuerdos de toda la modalidad, de cualquier campus', function () {
        $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
        $inC1 = Group::factory()->withQuota(20)->create(['term_id' => $this->term->id, 'campus_id' => $c1->id, 'code' => '3B', 'capacity' => 40, 'subject_id' => $this->group->subject_id]);
        $share = InterdepartmentalAgreement::factory()->sharedGroup(4)->create([
            'term_id' => $this->term->id, 'campus_id' => $c1->id, 'group_id' => $inC1->id,
            'from_department_id' => $this->ii->id, 'to_department_id' => $this->sc->id, 'career_id' => $this->career->id,
        ]);
        vacate(Role::JefeDepartamento, $this->sc->id);
        $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sc->id, 'valid_from' => today()->toDateString()])->create();

        agreementsAs($jefe, ['acuerdo' => $share->id])->assertSeeHtml('data-testid="agreement-accept"')->call('accept', $share->id)->assertOk();

        expect($share->fresh()->status)->toBe(AgreementStatus::Accepted);
    });

    it('otra modalidad: el jefe de proyecto docente de EAD no ve los acuerdos presenciales aunque pida ?modalidad=PRES', function () {
        modalityOf('EAD')->update(['is_active' => true]);
        $jpd = User::factory()->withRole(Role::JefeProyectoDocente, ['modality_id' => modalityOf('EAD')->id, 'department_id' => $this->sc->id])->create();

        agreementsAs($jpd, ['modalidad' => 'PRES'])->assertOk()->assertDontSee('LC-201')->assertSee('en EAD');
        agreementsAs($jpd, ['modalidad' => 'PRES', 'acuerdo' => $this->loan->id])->assertNotFound();
    });

    it('U2: el filtro de campus ofrece los campus de la modalidad con sus acuerdos, cada acuerdo con su etiqueta, y filtrar no reabre la página', function () {
        $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
        $inC1 = Group::factory()->withQuota(20)->create(['term_id' => $this->term->id, 'campus_id' => $c1->id, 'code' => '3B', 'capacity' => 40, 'subject_id' => $this->group->subject_id]);
        InterdepartmentalAgreement::factory()->sharedGroup(4)->create([
            'term_id' => $this->term->id, 'campus_id' => $c1->id, 'group_id' => $inC1->id,
            'from_department_id' => $this->ii->id, 'to_department_id' => $this->sc->id, 'career_id' => $this->career->id,
        ]);

        $page = agreementsAs($this->jefeSc);
        $dom = domOf($page);

        expect(textOf($dom->querySelector('[data-testid="campus-count-todos"]')))->toBe('3')
            ->and(textOf($dom->querySelector('[data-testid="campus-count-C1"]')))->toBe('1')
            ->and(textOf($dom->querySelector('[data-testid="campus-count-C2"]')))->toBe('2')
            ->and($dom->querySelectorAll('[data-testid="agreement-campus"]'))->toHaveCount(3);

        $page->set('campus', 'C1')->assertNoRedirect()->assertSet('agreementId', '')->assertDontSee('LC-201')->assertSee('en Presencial · Campus I');
    });

    it('un id que no es de un acuerdo visible es 404', function () {
        agreementsAs($this->jefeSc)->call('pick', 'no-es-uuid')->assertNotFound();
    });
});

it('la ruta pide entrar y muestra la pantalla a quien ve acuerdos', function () {
    $this->get(route('scheduling.agreements'))->assertRedirect();
    $this->actingAs($this->jefeSc)->get(route('scheduling.agreements'))->assertOk()->assertSee('Acuerdos');
});

it('fija la modalidad que resolvió: si después se abre otra antes por clave, la pantalla no cambia de modalidad', function () {
    modalityOf('EAD')->update(['is_active' => true]);
    $page = agreementsAs($this->jefeSc)->assertSet('modalidad', 'PRES')->assertSee('LC-201');

    TermOpening::factory()->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('EAD')->id]);

    $page->set('search', 'LC')->assertSet('modalidad', 'PRES')->assertSee('LC-201');
});
