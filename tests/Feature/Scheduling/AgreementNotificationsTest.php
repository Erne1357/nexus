<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Agreements\RejectAgreement;
use App\Modules\AcademicLoad\Events\AgreementRejected;
use App\Modules\AcademicLoad\Events\AgreementRequested;
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
use App\Modules\Scheduling\Actions\Agreements\RejectRoomLoan;
use App\Modules\Scheduling\Actions\Agreements\RejectSharedGroup;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Notifications\AgreementAnswered;
use App\Modules\Scheduling\Notifications\AgreementReceived;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
| Avisos de los acuerdos (tareas 3.6 y 3.7; supuesto por confirmar): al pedirse, a los titulares de
| `academic_load.agreement.decide` del departamento al que va dirigido, en el campus; al aceptarse o rechazarse, a los de
| `academic_load.agreement.request` del departamento que lo pidió, con el motivo. Llevan a Acuerdos con el acuerdo abierto.
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

it('un rechazo sin motivo no avisa: el evento sale después del commit', function () {
    Notification::fake();

    expect(fn () => app(RejectAgreement::class)->handle(($this->loan)(), '', $this->jefeSc))->toThrow(ValidationException::class);

    Notification::assertNothingSent();
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
