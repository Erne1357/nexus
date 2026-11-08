<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Agreements\CancelAgreement;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\AgreementRejected;
use App\Modules\AcademicLoad\Events\AgreementRequested;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Actions\Agreements\AcceptRoomLoan;
use App\Modules\Scheduling\Actions\Agreements\CancelRoomLoan;
use App\Modules\Scheduling\Actions\Agreements\RejectRoomLoan;
use App\Modules\Scheduling\Actions\Agreements\RejectSharedGroup;
use App\Modules\Scheduling\Actions\Sessions\AssignSessionRoom;
use App\Modules\Scheduling\Actions\Sessions\RemoveGroupSession;
use App\Modules\Scheduling\Actions\Sessions\SyncSessionLoans;
use App\Modules\Scheduling\Actions\Sessions\UpdateGroupSession;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Notifications\AgreementAnswered;
use App\Modules\Scheduling\Notifications\AgreementReceived;
use App\Modules\Scheduling\Notifications\LoanCancelled;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
| Avisos de los acuerdos (tareas 3.6 y 3.7; supuesto por confirmar): al pedirse, a los titulares de
| `academic_load.agreement.decide` del departamento al que va dirigido, en el campus; al aceptarse o rechazarse, a los de
| `academic_load.agreement.request` del departamento que lo pidió, con el motivo. Al cancelarse un préstamo vivo, a los
| mismos que al pedirlo (confirmado el 8 de noviembre de 2026). Llevan a Acuerdos con el acuerdo abierto.
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->term = Term::factory()->create(['code' => '20271']);
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->ii = Department::factory()->create(['code' => 'II', 'name' => 'Ingeniería Industrial']);
    $this->group = Group::factory()->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'code' => '3A',
        'subject_id' => Subject::factory()->create(['code' => 'SCC-1013', 'department_id' => $this->ii->id])->id,
    ]);
    $this->room = Room::factory()->create([
        'building_id' => Building::factory()->create(['campus_id' => $this->campus->id])->id, 'department_id' => $this->sc->id, 'code' => 'LC-201',
    ]);
    $this->jefeSc = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->sc->id])->create();
    $this->jefeIi = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->ii->id])->create();
    $this->subdireccion = User::factory()->withRole(Role::Subdireccion)->create();
    $this->loan = fn () => InterdepartmentalAgreement::factory()->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'group_id' => $this->group->id, 'room_id' => $this->room->id,
        'day' => 1, 'starts_at' => '09:00', 'ends_at' => '11:00', 'from_department_id' => $this->ii->id, 'to_department_id' => $this->sc->id,
        'requested_by' => $this->jefeIi->id,
    ]);
});

it('al pedirse un préstamo, avisa al jefe del departamento dueño y no a quien lo pidió ni a la subdirección', function () {
    Notification::fake();

    $loan = ($this->loan)();
    event(new AgreementRequested($loan));

    Notification::assertSentTo($this->jefeSc, AgreementReceived::class, function (AgreementReceived $notification) use ($loan): bool {
        $message = $notification->message($this->jefeSc);

        return $message->title === 'Ingeniería Industrial te pide LC-201'
            && $message->body === 'LC-201 · Lun 9:00–11:00'
            && $message->route === 'scheduling.agreements'
            && $message->parameters === ['acuerdo' => $loan->id]
            && $message->campusId === $this->campus->id
            && $message->termId === $this->term->id;
    });
    Notification::assertNotSentTo([$this->jefeIi, $this->subdireccion], AgreementReceived::class);
});

it('al proponerse un grupo compartido, avisa al jefe del departamento de la carrera', function () {
    Notification::fake();
    $career = Career::factory()->create(['code' => 'ISC', 'department_id' => $this->sc->id]);
    $share = InterdepartmentalAgreement::factory()->sharedGroup(6)->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'group_id' => $this->group->id,
        'from_department_id' => $this->ii->id, 'to_department_id' => $this->sc->id, 'career_id' => $career->id,
    ]);

    event(new AgreementRequested($share));

    Notification::assertSentTo($this->jefeSc, AgreementReceived::class, fn (AgreementReceived $notification): bool => $notification->message($this->jefeSc)->title === 'Ingeniería Industrial propone un grupo compartido'
        && $notification->message($this->jefeSc)->body === '6 lugares de ISC');
});

it('el aviso se queda en el campus: un jefe del mismo departamento en otro campus no lo recibe', function () {
    Notification::fake();
    vacate(Role::JefeDepartamento, $this->sc->id);
    $elsewhere = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => Campus::factory()->create()->id, 'department_id' => $this->sc->id, 'valid_from' => today()->toDateString()])->create();

    event(new AgreementRequested(($this->loan)()));

    Notification::assertNotSentTo($elsewhere, AgreementReceived::class);
});

it('al aceptarse un préstamo, avisa al departamento que lo pidió', function () {
    Notification::fake();
    $loan = ($this->loan)();

    app(AcceptRoomLoan::class)->handle($loan, $this->jefeSc);

    Notification::assertSentTo($this->jefeIi, AgreementAnswered::class, function (AgreementAnswered $notification) use ($loan): bool {
        $message = $notification->message($this->jefeIi);

        return $message->title === 'Sistemas y Computación aceptó tu préstamo'
            && $message->body === 'LC-201 · Lun 9:00–11:00'
            && $message->tone === 'success'
            && $message->parameters === ['acuerdo' => $loan->id];
    });
    Notification::assertNotSentTo([$this->jefeSc, $this->subdireccion], AgreementAnswered::class);
});

it('al rechazarse un préstamo, avisa con el motivo y que la sesión se quedó sin salón', function () {
    Notification::fake();
    GroupSession::factory()->for($this->group)->on(1, '09:00', '10:00')->inRoom($this->room)->create();

    app(RejectRoomLoan::class)->handle(($this->loan)(), 'Se cruza con una práctica.', $this->jefeSc);

    Notification::assertSentTo($this->jefeIi, AgreementAnswered::class, function (AgreementAnswered $notification): bool {
        $message = $notification->message($this->jefeIi);

        return $message->title === 'Sistemas y Computación rechazó tu préstamo'
            && $message->body === 'Motivo: Se cruza con una práctica. La sesión se quedó sin salón.'
            && $message->tone === 'warning';
    });
});

it('si el rechazo no dejó ninguna sesión sin salón, el aviso no lo dice', function () {
    Notification::fake();

    app(RejectRoomLoan::class)->handle(($this->loan)(), 'No hay lugar.', $this->jefeSc);

    Notification::assertSentTo($this->jefeIi, AgreementAnswered::class, fn (AgreementAnswered $notification): bool => $notification->message($this->jefeIi)->body === 'Motivo: No hay lugar.');
});

it('al rechazarse un grupo compartido, avisa con el motivo sin hablar de salón', function () {
    Notification::fake();
    $share = InterdepartmentalAgreement::factory()->sharedGroup(6)->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'group_id' => $this->group->id,
        'from_department_id' => $this->ii->id, 'to_department_id' => $this->sc->id,
    ]);

    app(RejectSharedGroup::class)->handle($share, 'Sin lugares.', $this->jefeSc);

    Notification::assertSentTo($this->jefeIi, AgreementAnswered::class, fn (AgreementAnswered $notification): bool => $notification->message($this->jefeIi)->body === 'Motivo: Sin lugares.');
});

it('el aviso sale después del commit: lo que se revierte no avisa', function () {
    Notification::fake();
    $loan = ($this->loan)();

    try {
        DB::transaction(function () use ($loan): void {
            app(RejectRoomLoan::class)->handle($loan, 'Se cruza.', $this->jefeSc);

            throw new RuntimeException('se revierte');
        });
    } catch (RuntimeException) {
    }

    Notification::assertNothingSent();
    expect($loan->fresh()->status->value)->toBe('proposed');
});

it('se abre en Acuerdos y deja de abrirse si el acuerdo ya no existe o ya no se ve', function () {
    $loan = ($this->loan)();
    event(new AgreementRequested($loan));
    $message = NotificationMessage::fromArray($this->jefeSc->notifications()->first()->data);
    $context = fn (User $user): Actor => app(Actor::class)->forUser($user);

    expect(AgreementReceived::unavailableReason($context($this->jefeSc), $message))->toBeNull()
        ->and(AgreementReceived::unavailableReason($context(User::factory()->withRole(Role::Docente)->create()), $message))->toBe('Ya no decides acuerdos de ese departamento.')
        ->and(AgreementReceived::unavailableReason($context($this->jefeSc), NotificationMessage::fromArray([...$this->jefeSc->notifications()->first()->data, 'parameters' => ['acuerdo' => fakeKey(70)]])))->toBe('Ese acuerdo ya no existe.');

    $loan->update(['status' => 'rejected', 'decided_by' => $this->jefeSc->id, 'decided_at' => now(), 'comment' => 'No.']);
    event(new AgreementRejected($loan));
    $answered = NotificationMessage::fromArray($this->jefeIi->notifications()->first()->data);

    expect(AgreementAnswered::unavailableReason($context($this->jefeIi), $answered))->toBeNull()
        ->and(AgreementAnswered::unavailableReason($context($this->jefeSc), $answered))->toBe('Ya no ves los acuerdos de ese departamento.');
});

it('el aviso de un acuerdo ya decidido o cancelado deja de abrirse, con su motivo', function () {
    $loan = ($this->loan)();
    event(new AgreementRequested($loan));
    $message = NotificationMessage::fromArray($this->jefeSc->notifications()->first()->data);
    $context = app(Actor::class)->forUser($this->jefeSc);

    $loan->update(['status' => 'cancelled', 'cancelled_by' => $this->jefeIi->id, 'cancelled_at' => now()]);
    expect(AgreementReceived::unavailableReason($context, $message))->toBe('Este acuerdo se canceló.');

    $loan->update(['status' => 'accepted', 'decided_by' => $this->jefeSc->id, 'decided_at' => now(), 'cancelled_by' => null, 'cancelled_at' => null]);
    expect(AgreementReceived::unavailableReason($context, $message))->toBe('Este acuerdo ya se decidió.');
});

it('al cancelar quien lo pidió un préstamo propuesto, avisa al jefe del departamento dueño y no a quien lo pidió ni a la subdirección', function () {
    Notification::fake();
    $loan = ($this->loan)();

    app(CancelRoomLoan::class)->handle($loan, $this->jefeIi);

    Notification::assertSentTo($this->jefeSc, LoanCancelled::class, function (LoanCancelled $notification) use ($loan): bool {
        $message = $notification->message($this->jefeSc);

        return $message->title === 'Ingeniería Industrial canceló el préstamo de LC-201 del lunes de 9:00 a 11:00'
            && $message->body === 'Ya no tienes que decidirlo.'
            && $message->route === 'scheduling.agreements'
            && $message->parameters === ['acuerdo' => $loan->id]
            && $message->campusId === $this->campus->id
            && $message->termId === $this->term->id;
    });
    Notification::assertSentToTimes($this->jefeSc, LoanCancelled::class, 1);
    Notification::assertNotSentTo([$this->jefeIi, $this->subdireccion], LoanCancelled::class);
});

it('al cancelarse solos los préstamos de una sesión que se movió (SyncSessionLoans), avisa uno por préstamo, también si estaba aceptado', function () {
    Notification::fake();
    $first = ($this->loan)();
    $first->update(['starts_at' => '07:00', 'ends_at' => '09:00', 'status' => AgreementStatus::Accepted, 'decided_by' => $this->jefeSc->id, 'decided_at' => now()]);
    $second = ($this->loan)();

    app(SyncSessionLoans::class)->resync($this->group, $this->room, 1, $this->jefeIi);

    expect($first->fresh()->status)->toBe(AgreementStatus::Cancelled)
        ->and($second->fresh()->status)->toBe(AgreementStatus::Cancelled);
    Notification::assertSentToTimes($this->jefeSc, LoanCancelled::class, 2);
    Notification::assertSentTo($this->jefeSc, LoanCancelled::class, fn (LoanCancelled $notification): bool => $notification->message($this->jefeSc)->parameters === ['acuerdo' => $first->id]
        && $notification->message($this->jefeSc)->title === 'Ingeniería Industrial canceló el préstamo de LC-201 del lunes de 7:00 a 9:00'
        && $notification->message($this->jefeSc)->body === 'Estaba aceptado: el salón ya no queda prestado a esa hora.');
    Notification::assertNotSentTo($this->jefeIi, LoanCancelled::class);
});

it('un préstamo rechazado no avisa al dueño como cancelado, ni lo que ya se decidió y ya no se puede cancelar', function () {
    Notification::fake();
    $rejected = ($this->loan)();
    app(RejectRoomLoan::class)->handle($rejected, 'No hay lugar.', $this->jefeSc);
    $accepted = ($this->loan)();
    app(AcceptRoomLoan::class)->handle($accepted, $this->jefeSc);

    expect(fn () => app(CancelAgreement::class)->handle($rejected->fresh(), $this->jefeIi))->toThrow(ValidationException::class)
        ->and(fn () => app(CancelRoomLoan::class)->handle($accepted->fresh(), $this->jefeIi))->toThrow(ValidationException::class, 'ya se decidió');

    Notification::assertNotSentTo([$this->jefeSc, $this->jefeIi], LoanCancelled::class);
});

it('retirar un grupo compartido no avisa como préstamo cancelado', function () {
    Notification::fake();
    $share = InterdepartmentalAgreement::factory()->sharedGroup(6)->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'group_id' => $this->group->id,
        'from_department_id' => $this->ii->id, 'to_department_id' => $this->sc->id,
    ]);

    app(CancelAgreement::class)->handle($share, $this->jefeIi);

    Notification::assertNothingSentTo($this->jefeSc);
});

it('el aviso de un préstamo cancelado sale después del commit: lo que se revierte no avisa', function () {
    Notification::fake();
    $loan = ($this->loan)();

    try {
        DB::transaction(function () use ($loan): void {
            app(CancelRoomLoan::class)->handle($loan, $this->jefeIi);

            throw new RuntimeException('se revierte');
        });
    } catch (RuntimeException) {
    }

    Notification::assertNothingSent();
    expect($loan->fresh()->status)->toBe(AgreementStatus::Proposed);
});

it('el aviso de un préstamo cancelado se abre en Acuerdos y deja de abrirse si el acuerdo ya no existe o ya no se ve', function () {
    $loan = ($this->loan)();
    app(CancelRoomLoan::class)->handle($loan, $this->jefeIi);
    $data = $this->jefeSc->notifications()->where('type', LoanCancelled::class)->sole()->data;
    $message = NotificationMessage::fromArray($data);
    $context = fn (User $user): Actor => app(Actor::class)->forUser($user);

    expect(LoanCancelled::unavailableReason($context($this->jefeSc), $message))->toBeNull()
        ->and(LoanCancelled::unavailableReason($context(User::factory()->withRole(Role::Docente)->create()), $message))->toBe('Ya no ves los acuerdos de ese departamento.')
        ->and(LoanCancelled::unavailableReason($context($this->jefeSc), NotificationMessage::fromArray([...$data, 'parameters' => ['acuerdo' => fakeKey(71)]])))->toBe('Ese acuerdo ya no existe.');
});

describe('préstamos que cancela la sesión al cambiar', function () {
    beforeEach(function () {
        AcademicLoad::factory()->inStatus(LoadStatus::Adjusting)->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'department_id' => $this->ii->id]);
        $this->session = GroupSession::factory()->for($this->group)->on(1, '09:00', '11:00')->inRoom($this->room)->create();
        $this->pending = ($this->loan)();
    });

    it('alargar la sesión reemplaza su préstamo: el dueño recibe solo el aviso del nuevo, no el de cancelado', function () {
        Notification::fake();

        app(UpdateGroupSession::class)->handle($this->session, 1, '09:00', '12:00', $this->session->kind, $this->jefeIi);

        expect($this->pending->fresh()->status)->toBe(AgreementStatus::Cancelled);
        Notification::assertSentToTimes($this->jefeSc, AgreementReceived::class, 1);
        Notification::assertSentTo($this->jefeSc, AgreementReceived::class, fn (AgreementReceived $notification): bool => $notification->message($this->jefeSc)->body === 'LC-201 · Lun 9:00–12:00');
        Notification::assertNotSentTo($this->jefeSc, LoanCancelled::class);
    });

    it('mover la sesión pegada a su préstamo propuesto también lo reemplaza: solo el aviso del nuevo', function () {
        Notification::fake();

        app(UpdateGroupSession::class)->handle($this->session, 1, '11:00', '13:00', $this->session->kind, $this->jefeIi);

        Notification::assertSentTo($this->jefeSc, AgreementReceived::class, fn (AgreementReceived $notification): bool => $notification->message($this->jefeSc)->body === 'LC-201 · Lun 11:00–13:00');
        Notification::assertNotSentTo($this->jefeSc, LoanCancelled::class);
    });

    it('un préstamo aceptado que se suelta siempre avisa, aunque se pida otro pegado a él para las nuevas horas', function () {
        Notification::fake();
        $this->pending->update(['status' => AgreementStatus::Accepted, 'decided_by' => $this->jefeSc->id, 'decided_at' => now()]);

        app(UpdateGroupSession::class)->handle($this->session, 1, '11:00', '13:00', $this->session->kind, $this->jefeIi);

        expect($this->pending->fresh()->status)->toBe(AgreementStatus::Cancelled);
        Notification::assertSentTo($this->jefeSc, LoanCancelled::class, fn (LoanCancelled $notification): bool => $notification->message($this->jefeSc)->parameters === ['acuerdo' => $this->pending->id]
            && $notification->message($this->jefeSc)->body === 'Estaba aceptado: el salón ya no queda prestado a esa hora.');
        Notification::assertSentTo($this->jefeSc, AgreementReceived::class, fn (AgreementReceived $notification): bool => $notification->message($this->jefeSc)->body === 'LC-201 · Lun 11:00–13:00');
    });

    it('un propuesto que se suelta mientras se pide otro para una sesión que no tiene que ver sí avisa', function () {
        Notification::fake();
        GroupSession::factory()->for($this->group)->on(1, '14:00', '16:00')->inRoom($this->room)->create();

        app(RemoveGroupSession::class)->handle($this->session, $this->jefeIi);

        Notification::assertSentTo($this->jefeSc, LoanCancelled::class, fn (LoanCancelled $notification): bool => $notification->message($this->jefeSc)->parameters === ['acuerdo' => $this->pending->id]);
        Notification::assertSentTo($this->jefeSc, AgreementReceived::class, fn (AgreementReceived $notification): bool => $notification->message($this->jefeSc)->body === 'LC-201 · Lun 14:00–16:00');
    });

    it('si el reemplazo no se puede pedir porque otro grupo ya aparta el salón, el aviso de cancelado sí sale', function () {
        Notification::fake();
        InterdepartmentalAgreement::factory()->create([
            'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'room_id' => $this->room->id,
            'group_id' => Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id])->id,
            'day' => 1, 'starts_at' => '11:00', 'ends_at' => '12:00', 'from_department_id' => $this->ii->id, 'to_department_id' => $this->sc->id,
        ]);

        app(UpdateGroupSession::class)->handle($this->session, 1, '09:00', '12:00', $this->session->kind, $this->jefeIi);

        Notification::assertSentTo($this->jefeSc, LoanCancelled::class, fn (LoanCancelled $notification): bool => $notification->message($this->jefeSc)->parameters === ['acuerdo' => $this->pending->id]);
        Notification::assertNotSentTo($this->jefeSc, AgreementReceived::class);
    });

    it('cambiar la sesión a un salón de otro departamento avisa al dueño del salón de antes que se canceló y pide el nuevo a su dueño', function () {
        Notification::fake();
        $cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
        $jefeCb = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $cb->id])->create();
        $other = Room::factory()->create(['building_id' => $this->room->building_id, 'department_id' => $cb->id, 'code' => 'CB-101']);

        app(AssignSessionRoom::class)->handle($this->session, $other, $this->jefeIi);

        Notification::assertSentToTimes($this->jefeSc, LoanCancelled::class, 1);
        Notification::assertSentTo($this->jefeSc, LoanCancelled::class, fn (LoanCancelled $notification): bool => $notification->message($this->jefeSc)->parameters === ['acuerdo' => $this->pending->id]);
        Notification::assertSentTo($jefeCb, AgreementReceived::class, fn (AgreementReceived $notification): bool => $notification->message($jefeCb)->title === 'Ingeniería Industrial te pide CB-101');
        Notification::assertNotSentTo($jefeCb, LoanCancelled::class);
    });

    it('quitar la sesión cancela su préstamo y avisa al dueño', function () {
        Notification::fake();

        app(RemoveGroupSession::class)->handle($this->session, $this->jefeIi);

        expect($this->pending->fresh()->status)->toBe(AgreementStatus::Cancelled);
        Notification::assertSentToTimes($this->jefeSc, LoanCancelled::class, 1);
        Notification::assertNotSentTo($this->jefeSc, AgreementReceived::class);
    });
});

it('el aviso de cancelado dice las horas sin cero a la izquierda, también antes de la una', function () {
    Notification::fake();
    $loan = ($this->loan)();
    $loan->update(['starts_at' => '00:30', 'ends_at' => '01:00']);

    app(CancelRoomLoan::class)->handle($loan->fresh(), $this->jefeIi);

    Notification::assertSentTo($this->jefeSc, LoanCancelled::class, fn (LoanCancelled $notification): bool => $notification->message($this->jefeSc)->title === 'Ingeniería Industrial canceló el préstamo de LC-201 del lunes de 0:30 a 1:00'
        && $notification->message($this->jefeSc)->icon === 'o-building-office');
});
