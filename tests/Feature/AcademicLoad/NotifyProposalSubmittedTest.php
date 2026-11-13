<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Proposals\NotifyProposalSubmitted;
use App\Modules\AcademicLoad\Actions\Proposals\SubmitCareerProposal;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Notifications\ProposalSubmitted;
use App\Modules\AcademicLoad\Notifications\ProposalSubmittedByPeer;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NotificationMessage;
use Illuminate\Support\Facades\Notification;

/*
| Tareas 2.7 y 2.9: al enviar una propuesta, el jefe de cada departamento con su parte pendiente recibe
| «<carrera> envió su propuesta» con cuántas materias y grupos le pide en todos los campus; al reenviarla, «reenvió».
| Los departamentos que ya aceptaron no reciben nada. Los demás coordinadores de la carrera en la modalidad reciben
| «<quién> envió la propuesta de <carrera>» (M6, MS18).
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->career = Career::factory()->create(['code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales']);
    $this->proposal = CareerProposal::factory()->create([
        'term_id' => Term::factory()->create(['code' => '20271'])->id,
        'career_id' => $this->career->id,
    ]);
    TermOpening::factory()->create(['term_id' => $this->proposal->term_id, 'modality_id' => $this->campus->modality_id]);
    CareerCampus::factory()->create(['career_id' => $this->career->id, 'campus_id' => $this->campus->id]);
    $this->cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->jefeCb = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->cb->id])->create();
    $this->jefeSc = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sc->id])->create();
    $this->coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->career->id])->create();
});

function rowFor(Department $department, int $groups): ProposalItem
{
    return ProposalItem::factory()->create([
        'career_proposal_id' => test()->proposal->id,
        'campus_id' => test()->campus->id,
        'subject_id' => Subject::factory()->create(['department_id' => $department->id])->id,
        'requested_groups' => $groups,
    ]);
}

it('al enviar, avisa al jefe de cada departamento con cuántas materias y grupos le pide', function () {
    Notification::fake();
    rowFor($this->cb, 2);
    rowFor($this->cb, 3);
    rowFor($this->sc, 1);
    rowFor($this->sc, 0);

    app(SubmitCareerProposal::class)->handle($this->proposal, $this->coordinador);

    Notification::assertSentTo($this->jefeCb, ProposalSubmitted::class, function (ProposalSubmitted $notification) {
        $message = $notification->message($this->jefeCb);

        return $message->title === 'Ingeniería en Sistemas Computacionales envió su propuesta'
            && $message->body === 'Presencial · 2 materias, 5 grupos'
            && $message->route === 'academic-load.inbox'
            && $message->parameters === ['depto' => 'CB', 'carrera' => $this->career->id]
            && $message->termId === $this->proposal->term_id
            && $message->modalityId === $this->proposal->modality_id
            && $message->campusId === null;
    });
    Notification::assertSentTo($this->jefeSc, ProposalSubmitted::class, fn (ProposalSubmitted $notification) => $notification->message($this->jefeSc)->body === 'Presencial · 1 materia, 1 grupo');
    Notification::assertNotSentTo($this->coordinador, ProposalSubmitted::class);
});

it('al reenviar, avisa «reenvió» solo a los departamentos que no habían aceptado', function () {
    Notification::fake();
    rowFor($this->cb, 2);
    rowFor($this->sc, 1);
    $this->proposal->update(['status' => ProposalStatus::Returned]);
    ProposalReview::factory()->accepted()->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $this->sc->id]);
    ProposalReview::factory()->returned('Revisa Cálculo')->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $this->cb->id]);

    app(SubmitCareerProposal::class)->handle($this->proposal, $this->coordinador);

    Notification::assertSentTo($this->jefeCb, ProposalSubmitted::class, fn (ProposalSubmitted $notification) => $notification->message($this->jefeCb)->title === 'Ingeniería en Sistemas Computacionales reenvió su propuesta');
    Notification::assertNotSentTo($this->jefeSc, ProposalSubmitted::class);
});

// Los avisos van a todos los puestos de la persona (spec 8.4), no solo al perfil con que entró: quien envía como
// coordinador recibe el aviso de su departamento como jefe.
it('un jefe que también coordina recibe el aviso de propuesta aunque su último perfil sea Coordinación', function () {
    Notification::fake();
    $industrial = Department::factory()->create(['code' => 'IND', 'name' => 'Ingeniería Industrial']);
    $jefeCoordinador = User::factory()
        ->withRole(Role::JefeDepartamento, ['department_id' => $industrial->id])
        ->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->career->id])
        ->create(['last_role' => Role::Coordinador]);
    rowFor($industrial, 2);

    $this->actingAs($jefeCoordinador);
    app(SubmitCareerProposal::class)->handle($this->proposal, $jefeCoordinador);

    expect(app(Actor::class)->activeRole())->toBe(Role::Coordinador);
    Notification::assertSentTo($jefeCoordinador, ProposalSubmitted::class, fn (ProposalSubmitted $notification) => $notification->message($jefeCoordinador)->parameters['depto'] === 'IND');
});

it('sin departamentos pendientes no avisa a nadie', function () {
    Notification::fake();

    expect(app(NotifyProposalSubmitted::class)->handle($this->proposal, []))->toBe(0);

    Notification::assertNothingSent();
});

it('deja de poder abrirse si el usuario ya no es jefe del departamento o la propuesta ya no existe', function () {
    rowFor($this->cb, 2);
    app(NotifyProposalSubmitted::class)->handle($this->proposal, [$this->cb->id]);
    $message = NotificationMessage::fromArray($this->jefeCb->notifications()->first()->data);

    expect(ProposalSubmitted::unavailableReason(app(Actor::class)->forUser($this->jefeSc), $message))->toBe('Ya no eres jefe de Ciencias Básicas.')
        ->and(ProposalSubmitted::unavailableReason(app(Actor::class)->forUser($this->jefeCb), NotificationMessage::fromArray([...$this->jefeCb->notifications()->first()->data, 'parameters' => ['depto' => 'CB', 'carrera' => fakeKey(77)]])))->toBe('Esa propuesta ya no existe.');
});

it('sigue abriéndose si la carrera se borró lógicamente: la propuesta existe', function () {
    rowFor($this->cb, 2);
    app(NotifyProposalSubmitted::class)->handle($this->proposal, [$this->cb->id]);
    $message = NotificationMessage::fromArray($this->jefeCb->notifications()->first()->data);
    Career::query()->whereKey($this->proposal->career_id)->delete();

    expect(ProposalSubmitted::unavailableReason(app(Actor::class)->forUser($this->jefeCb), $message))->toBeNull();
});

it('guarda el puesto de jefe en el aviso', function () {
    rowFor($this->cb, 2);

    app(NotifyProposalSubmitted::class)->handle($this->proposal, [$this->cb->id]);

    expect($this->jefeCb->notifications()->first()->data['role'])->toBe('jefe_departamento');
});

it('M6, MS18: cualquier coordinador de la carrera envía y se avisa a los demás', function () {
    Notification::fake();
    $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    CareerCampus::factory()->create(['career_id' => $this->career->id, 'campus_id' => $c1->id]);
    $deC1 = User::factory()->withRole(Role::Coordinador, ['campus_id' => $c1->id, 'career_id' => $this->career->id])->create(['name' => 'Héctor Villalobos']);
    $sinCampus = User::factory()->withRole(Role::Coordinador, ['career_id' => $this->career->id])->create(['name' => 'Rocío Barraza']);
    $otraCarrera = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => Career::factory()->create()->id])->create();
    $this->coordinador->update(['name' => 'Daniela Quintero']);
    rowFor($this->cb, 2);

    expect($deC1->can('submit', $this->proposal))->toBeTrue()
        ->and($this->coordinador->can('submit', $this->proposal))->toBeTrue();

    app(SubmitCareerProposal::class)->handle($this->proposal, $this->coordinador);

    Notification::assertSentTo($this->jefeCb, ProposalSubmitted::class);
    foreach ([$deC1, $sinCampus] as $peer) {
        Notification::assertSentTo($peer, ProposalSubmittedByPeer::class, function (ProposalSubmittedByPeer $notification) use ($peer) {
            $message = $notification->message($peer);

            return $message->title === 'Daniela Quintero envió la propuesta de Ingeniería en Sistemas Computacionales'
                && $message->route === 'academic-load.proposal'
                && $message->parameters === ['carrera' => $this->career->id]
                && $message->modalityId === $this->proposal->modality_id
                && $message->campusId === null;
        });
    }
    Notification::assertNotSentTo([$this->coordinador, $otraCarrera, $this->jefeCb], ProposalSubmittedByPeer::class);
});
