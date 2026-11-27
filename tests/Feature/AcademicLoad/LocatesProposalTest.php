<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Notifications\ProposalSubmitted;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NotificationMessage;

/*
| MS14 (supuesto por confirmar): un aviso de la propuesta la busca por su periodo, su modalidad y su carrera (M6). Los
| avisos guardados de antes, con solo el campus, pasaron a llevar la modalidad al contraer (2026_11_12_211101), así que
| ya no hay respaldo por el campus: un aviso sin modalidad no encuentra la propuesta.
*/

beforeEach(function () {
    $this->proposal = CareerProposal::factory()->create();
    $this->department = Department::factory()->create();
    $this->admin = User::factory()->admin()->create();
});

function proposalNotice(CareerProposal $proposal, Department $department, ?string $modalityId, ?string $campusId = null): NotificationMessage
{
    return new NotificationMessage(
        title: 'Propuesta', body: 'x', icon: 'o-bell', route: 'academic-load.inbox',
        parameters: ['depto' => $department->code, 'carrera' => $proposal->career_id],
        termId: $proposal->term_id, campusId: $campusId, modalityId: $modalityId,
    );
}

it('MS14: el aviso con la modalidad encuentra la propuesta', function () {
    $context = app(Actor::class)->forUser($this->admin);

    expect(ProposalSubmitted::unavailableReason($context, proposalNotice($this->proposal, $this->department, $this->proposal->modality_id)))->toBeNull();
});

it('MS14: el aviso con solo el campus ya no busca la modalidad por el campus: la propuesta no se encuentra', function () {
    $context = app(Actor::class)->forUser($this->admin);

    expect(ProposalSubmitted::unavailableReason($context, proposalNotice($this->proposal, $this->department, null, Campus::factory()->create()->id)))
        ->toBe('Esa propuesta ya no existe.');
});
