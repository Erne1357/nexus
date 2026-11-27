<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\Proposals\ReturnProposalReview;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Notifications\ProposalReturned;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NotificationMessage;
use Illuminate\Support\Facades\Notification;

/*
| Tareas 2.8 y 2.9: al regresar un departamento su parte, los coordinadores de la carrera en la modalidad, de cualquier
| campus (MS18), reciben «<departamento> regresó tu propuesta» con el comentario del jefe, que los lleva a la propuesta
| con las materias de ese departamento resaltadas.
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->career = Career::factory()->create(['code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales']);
    $this->cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $this->proposal = CareerProposal::factory()->submitted()->create([
        'term_id' => Term::factory()->create(['code' => '20271'])->id,
        'career_id' => $this->career->id,
    ]);
    $this->part = ProposalReview::factory()->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $this->cb->id]);
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->cb->id])->create();
    $this->coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->career->id])->create();
    $this->otroCampus = User::factory()->withRole(Role::Coordinador, ['campus_id' => Campus::factory()->create()->id, 'career_id' => $this->career->id])->create();
    modalityOf('EAD')->update(['is_active' => true]);
    $this->otraModalidad = User::factory()->withRole(Role::Coordinador, ['modality_id' => modalityOf('EAD')->id, 'career_id' => $this->career->id])->create();
});

it('al regresar una parte, avisa a los coordinadores de la carrera con el comentario, y no a los de otra modalidad', function () {
    Notification::fake();

    app(ReturnProposalReview::class)->handle($this->part, 'Cálculo Integral: 4 grupos para 96 esperados; revisa el estimado.', $this->jefe);

    Notification::assertSentTo($this->coordinador, ProposalReturned::class, function (ProposalReturned $notification) {
        $message = $notification->message($this->coordinador);

        return $message->title === 'Ciencias Básicas regresó tu propuesta'
            && $message->body === 'Cálculo Integral: 4 grupos para 96 esperados; revisa el estimado.'
            && $message->route === 'academic-load.proposal'
            && $message->parameters === ['carrera' => $this->career->id, 'depto' => 'CB']
            && $message->tone === 'warning'
            && $message->modalityId === $this->proposal->modality_id
            && $message->campusId === null;
    });
    Notification::assertNotSentTo([$this->otraModalidad, $this->jefe], ProposalReturned::class);
});

it('se abre en la propuesta y deja de abrirse si ya no coordina la carrera en la modalidad', function () {
    app(ReturnProposalReview::class)->handle($this->part, 'Revisa Cálculo.', $this->jefe);
    $message = NotificationMessage::fromArray($this->coordinador->notifications()->first()->data);

    expect(ProposalReturned::unavailableReason(app(Actor::class)->forUser($this->coordinador), $message))->toBeNull()
        ->and(ProposalReturned::unavailableReason(app(Actor::class)->forUser($this->otroCampus), $message))->toBeNull()
        ->and(ProposalReturned::unavailableReason(app(Actor::class)->forUser($this->otraModalidad), $message))->toBe('Ya no coordinas Ingeniería en Sistemas Computacionales.')
        ->and(ProposalReturned::unavailableReason(app(Actor::class)->forUser($this->coordinador), NotificationMessage::fromArray([...$this->coordinador->notifications()->first()->data, 'parameters' => ['carrera' => fakeKey(70), 'depto' => 'CB']])))->toBe('Esa propuesta ya no existe.');
});

it('sigue abriéndose si la carrera se borró lógicamente: la propuesta existe', function () {
    app(ReturnProposalReview::class)->handle($this->part, 'Revisa Cálculo.', $this->jefe);
    $message = NotificationMessage::fromArray($this->coordinador->notifications()->first()->data);
    Career::query()->whereKey($this->proposal->career_id)->delete();

    expect(ProposalReturned::unavailableReason(app(Actor::class)->forUser($this->coordinador), $message))->toBeNull();
});

it('guarda el puesto de coordinador en el aviso', function () {
    app(ReturnProposalReview::class)->handle($this->part, 'Revisa Cálculo.', $this->jefe);

    expect($this->coordinador->notifications()->first()->data['role'])->toBe('coordinador');
});

it('MS18: al regresar una parte avisa a todos los coordinadores de la carrera en la modalidad, de cualquier campus', function () {
    Notification::fake();
    $sinCampus = User::factory()->withRole(Role::Coordinador, ['career_id' => $this->career->id])->create();

    app(ReturnProposalReview::class)->handle($this->part, 'Revisa Cálculo.', $this->jefe);

    Notification::assertSentTo([$this->coordinador, $this->otroCampus, $sinCampus], ProposalReturned::class,
        fn (ProposalReturned $notification, array $channels, User $notifiable) => $notification->message($notifiable)->modalityId === $this->proposal->modality_id);
    Notification::assertNotSentTo($this->jefe, ProposalReturned::class);
});
