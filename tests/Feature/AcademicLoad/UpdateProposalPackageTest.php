<?php

use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Proposals\CreateProposalPackage;
use App\Modules\AcademicLoad\Actions\Proposals\UpdateProposalPackage;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Illuminate\Validation\ValidationException;

/*
| Guardar el panel de un paquete (tarea 5.6b): sus materias y su nombre van juntos, en una sola transacción, para que la
| página nunca deje las materias cambiadas y el nombre sin cambiar.
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->proposal = CareerProposal::factory()->create();
    $campus = Campus::factory()->create();
    $this->first = Subject::factory()->create();
    $this->second = Subject::factory()->create();

    foreach ([$this->first, $this->second] as $subject) {
        ProposalItem::factory()->create(['career_proposal_id' => $this->proposal->id, 'campus_id' => $campus->id, 'subject_id' => $subject->id, 'captured_at' => now()]);
    }

    $this->package = app(CreateProposalPackage::class)->handle($this->proposal, [$this->first->id], null, $this->actor);
});

it('cambia las materias y el nombre a la vez', function () {
    $package = app(UpdateProposalPackage::class)->handle($this->package, [$this->first->id, $this->second->id], 'Matutino', $this->actor);

    expect($package->name)->toBe('Matutino')
        ->and($package->subjects()->pluck('subject_id')->all())->toEqualCanonicalizing([$this->first->id, $this->second->id]);
});

it('si el nombre no se acepta, tampoco cambian las materias', function () {
    expect(fn () => app(UpdateProposalPackage::class)->handle($this->package, [$this->first->id, $this->second->id], str_repeat('x', 61), $this->actor))
        ->toThrow(ValidationException::class);

    expect($this->package->fresh()->name)->toBeNull()
        ->and($this->package->subjects()->pluck('subject_id')->all())->toBe([$this->first->id]);
});
