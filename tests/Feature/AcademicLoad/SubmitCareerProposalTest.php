<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Proposals\SubmitCareerProposal;
use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Events\CareerProposalSubmitted;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| SubmitCareerProposal envía la propuesta de carrera a los departamentos (reglas-de-negocio.md, "Propuesta de
| carrera"): cada departamento con grupos pedidos recibe su parte, que decide completa; la propuesta queda
| aceptada cuando todas sus partes lo están (confirmado el 2 de noviembre de 2026). Exige al menos una materia
| con grupos. Al reenviar, la parte aceptada no cambia, aunque se haya aceptado con la propuesta regresada
| (confirmado el 2 de noviembre de 2026); la regresada vuelve a pendiente sin comentario, la pendiente sigue
| pendiente y la de un departamento al que ya no se le piden grupos desaparece (supuestos por confirmar).
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->proposal = CareerProposal::factory()->create([
        'term_id' => Term::factory()->create(['code' => '20271'])->id,
        'campus_id' => Campus::factory()->create(['code' => 'C2'])->id,
        'career_id' => Career::factory()->create(['code' => 'ISC'])->id,
    ]);
    TermOpening::factory()->create(['term_id' => $this->proposal->term_id, 'modality_id' => $this->proposal->modality_id]);
    CareerCampus::factory()->create(['career_id' => $this->proposal->career_id, 'campus_id' => $this->proposal->campus_id]);
    $this->cb = Department::factory()->create(['code' => 'CB']);
    $this->sc = Department::factory()->create(['code' => 'SC']);
    $this->ii = Department::factory()->create(['code' => 'II']);
});

function submitProposal(?CareerProposal $proposal = null): CareerProposal
{
    return app(SubmitCareerProposal::class)->handle($proposal ?? test()->proposal, test()->actor);
}

/**
 * Una fila de la propuesta de la prueba con una materia del departamento.
 */
function proposalRowIn(Department $department, int $groups): ProposalItem
{
    return ProposalItem::factory()->create([
        'career_proposal_id' => test()->proposal->id,
        'subject_id' => Subject::factory()->create(['department_id' => $department->id])->id,
        'requested_groups' => $groups,
    ]);
}

/**
 * Las partes de la propuesta por clave de departamento: [estado, comentario].
 *
 * @return array<string, array{0: string, 1: ?string}>
 */
function proposalPartsOf(CareerProposal $proposal): array
{
    return $proposal->reviews()->with('department')->get()
        ->sortBy(fn (ProposalReview $review): string => $review->department->code)
        ->mapWithKeys(fn (ProposalReview $review): array => [$review->department->code => [$review->status->value, $review->comment]])
        ->all();
}

/**
 * Los errores de la ValidationException del envío, o null si no lanza.
 *
 * @return array<string, list<string>>|null
 */
function submitErrors(?CareerProposal $proposal = null): ?array
{
    try {
        submitProposal($proposal);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return null;
}

it('envía el borrador: queda enviada, con quién y cuándo, y una parte pendiente por departamento con grupos', function () {
    proposalRowIn($this->cb, 2);
    proposalRowIn($this->cb, 1);
    proposalRowIn($this->sc, 1);
    proposalRowIn($this->ii, 0);

    $proposal = submitProposal();

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Submitted)
        ->and($proposal->submitted_by)->toBe($this->actor->id)
        ->and($proposal->submitted_at)->not->toBeNull()
        ->and(proposalPartsOf($proposal))->toBe([
            'CB' => ['pending', null],
            'SC' => ['pending', null],
        ]);
});

it('deja bitácora del envío con las partes pendientes', function () {
    proposalRowIn($this->sc, 1);
    proposalRowIn($this->cb, 1);

    submitProposal();

    $activity = Activity::query()->sole();
    expect($activity->subject_id)->toBe($this->proposal->id)
        ->and($activity->causer_id)->toBe($this->actor->id)
        ->and($activity->event)->toBe('submitted')
        ->and($activity->attribute_changes['old'])->toBe(['status' => 'draft'])
        ->and($activity->attribute_changes['attributes'])->toBe(['status' => 'submitted'])
        ->and($activity->properties->all())->toBe([
            'pending_department_ids' => [$this->cb->id, $this->sc->id],
            'accepted_department_ids' => [],
            'reset_reviews' => [],
            'removed_reviews' => [],
        ])
        ->and($activity->description)->toBe('Envió la propuesta de ISC en C2 para el periodo 20271');
});

it('avisa con CareerProposalSubmitted y los departamentos pendientes, por clave, solo después del commit', function () {
    Event::fake([CareerProposalSubmitted::class]);
    proposalRowIn($this->sc, 1);
    proposalRowIn($this->cb, 1);

    submitProposal();

    Event::assertDispatched(CareerProposalSubmitted::class, fn (CareerProposalSubmitted $event): bool => $event->proposal->is($this->proposal)
        && $event->pendingDepartmentIds === [$this->cb->id, $this->sc->id]);
});

it('no avisa si la transacción externa se revierte', function () {
    Event::fake([CareerProposalSubmitted::class]);
    proposalRowIn($this->cb, 1);

    try {
        DB::transaction(function () {
            submitProposal();

            throw new RuntimeException('revertir');
        });
    } catch (RuntimeException) {
    }

    Event::assertNotDispatched(CareerProposalSubmitted::class);
    expect($this->proposal->fresh()->status)->toBe(ProposalStatus::Draft)
        ->and(ProposalReview::query()->count())->toBe(0);
});

it('exige al menos una materia con grupos y no escribe nada', function () {
    proposalRowIn($this->cb, 0);

    expect(submitErrors())->toBe(['requested_groups' => ['Pide al menos un grupo antes de enviar la propuesta.']])
        ->and($this->proposal->fresh()->status)->toBe(ProposalStatus::Draft)
        ->and(ProposalReview::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
});

it('no envía una propuesta ya enviada ni una aceptada', function (ProposalStatus $status, string $message) {
    $this->proposal->update(['status' => $status]);
    proposalRowIn($this->cb, 1);

    expect(submitErrors())->toBe(['status' => [$message]])
        ->and(ProposalReview::query()->count())->toBe(0);
})->with([
    'enviada' => [ProposalStatus::Submitted, 'La propuesta ya se envió.'],
    'aceptada' => [ProposalStatus::Accepted, 'La propuesta ya se aceptó.'],
]);

it('usa el estado de la propuesta en la base y no el del modelo que llegó', function () {
    proposalRowIn($this->cb, 1);
    $stale = CareerProposal::query()->findOrFail($this->proposal->id);
    DB::table('career_proposals')->where('id', $this->proposal->id)->update(['status' => 'submitted']);

    expect(submitErrors($stale))->toBe(['status' => ['La propuesta ya se envió.']]);
});

it('envía aunque los catálogos de los departamentos involucrados no estén validados: es solo un aviso', function () {
    proposalRowIn($this->cb, 1);

    expect(submitProposal()->status)->toBe(ProposalStatus::Submitted);
});

describe('al reenviar una propuesta regresada', function () {
    beforeEach(function () {
        $this->proposal->update(['status' => ProposalStatus::Returned, 'submitted_by' => User::factory()->create()->id, 'submitted_at' => now()->subDay()]);
        proposalRowIn($this->cb, 2);
        proposalRowIn($this->sc, 1);
        $this->accepted = ProposalReview::factory()->accepted()->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $this->cb->id]);
        $this->returned = ProposalReview::factory()->returned('Pide un grupo más de Redes.')->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $this->sc->id]);
    });

    it('deja intacta la parte aceptada, regresa a pendiente la regresada sin comentario y crea la del departamento nuevo', function () {
        proposalRowIn($this->ii, 1);

        submitProposal();

        $accepted = $this->accepted->fresh();
        $returned = $this->returned->fresh();
        expect($this->proposal->fresh()->status)->toBe(ProposalStatus::Submitted)
            ->and(proposalPartsOf($this->proposal))->toBe([
                'CB' => ['accepted', null],
                'II' => ['pending', null],
                'SC' => ['pending', null],
            ])
            ->and($accepted->decided_by)->toBe($this->accepted->decided_by)
            ->and($accepted->decided_at->equalTo($this->accepted->decided_at))->toBeTrue()
            ->and($returned->decided_by)->toBeNull()
            ->and($returned->decided_at)->toBeNull()
            ->and($this->proposal->fresh()->submitted_by)->toBe($this->actor->id);
    });

    it('deja pendiente, sin reiniciarla, la parte que nadie decidió mientras estaba regresada', function () {
        proposalRowIn($this->ii, 1);
        $pending = ProposalReview::factory()->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $this->ii->id]);

        submitProposal();

        expect(proposalPartsOf($this->proposal))->toBe([
            'CB' => ['accepted', null],
            'II' => ['pending', null],
            'SC' => ['pending', null],
        ])
            ->and($pending->fresh()->decided_by)->toBeNull()
            ->and(Activity::query()->where('event', 'submitted')->sole()->properties->all())->toBe([
                'pending_department_ids' => [$this->ii->id, $this->sc->id],
                'accepted_department_ids' => [$this->cb->id],
                'reset_reviews' => [['department_id' => $this->sc->id, 'status' => 'returned', 'comment' => 'Pide un grupo más de Redes.']],
                'removed_reviews' => [],
            ]);
    });

    it('borra la parte del departamento al que ya no se le piden grupos', function () {
        ProposalItem::query()->whereHas('subject', fn ($query) => $query->where('department_id', $this->sc->id))->update(['requested_groups' => 0]);
        proposalRowIn($this->ii, 1);

        submitProposal();

        expect(proposalPartsOf($this->proposal))->toBe([
            'CB' => ['accepted', null],
            'II' => ['pending', null],
        ]);
    });

    it('deja en la bitácora el comentario que se limpió y la parte que se borró', function () {
        ProposalItem::query()->whereHas('subject', fn ($query) => $query->where('department_id', $this->sc->id))->update(['requested_groups' => 0]);
        proposalRowIn($this->ii, 1);
        $pendingInII = ProposalReview::factory()->returned('Sin docentes para Calidad.')->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $this->ii->id]);

        submitProposal();

        $activity = Activity::query()->where('event', 'submitted')->sole();
        expect($activity->attribute_changes['old'])->toBe(['status' => 'returned'])
            ->and($activity->attribute_changes['attributes'])->toBe(['status' => 'submitted'])
            ->and($activity->properties->all())->toBe([
                'pending_department_ids' => [$this->ii->id],
                'accepted_department_ids' => [$this->cb->id],
                'reset_reviews' => [['department_id' => $this->ii->id, 'status' => 'returned', 'comment' => 'Sin docentes para Calidad.']],
                'removed_reviews' => [['department_id' => $this->sc->id, 'status' => 'returned', 'comment' => 'Pide un grupo más de Redes.']],
            ])
            ->and($activity->description)->toBe('Reenvió la propuesta de ISC en C2 para el periodo 20271')
            ->and($pendingInII->fresh()->status)->toBe(ProposalReviewStatus::Pending);
    });

    it('queda aceptada si todas las partes que quedan ya estaban aceptadas, y avisa sin pendientes', function () {
        Event::fake([CareerProposalSubmitted::class]);
        ProposalItem::query()->whereHas('subject', fn ($query) => $query->where('department_id', $this->sc->id))->update(['requested_groups' => 0]);

        submitProposal();

        expect($this->proposal->fresh()->status)->toBe(ProposalStatus::Accepted)
            ->and(proposalPartsOf($this->proposal))->toBe(['CB' => ['accepted', null]])
            ->and(Activity::query()->where('event', 'submitted')->sole()->attribute_changes['attributes'])->toBe(['status' => 'accepted']);
        Event::assertDispatched(CareerProposalSubmitted::class, fn (CareerProposalSubmitted $event): bool => $event->pendingDepartmentIds === []);
    });
});

it('no envía si el periodo ya no está abierto en el campus y no escribe nada', function () {
    proposalRowIn($this->cb, 1);
    TermOpening::query()->delete();

    expect(submitErrors())->toBe(['term' => ['El periodo 20271 no está abierto en C2.']])
        ->and($this->proposal->fresh()->status)->toBe(ProposalStatus::Draft)
        ->and(ProposalReview::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
});

it('no envía si la carrera ya no se ofrece en el campus y no escribe nada', function (Closure $stopOffering) {
    proposalRowIn($this->cb, 1);
    $stopOffering();

    expect(submitErrors())->toBe(['career' => ['ISC no se ofrece en C2.']])
        ->and($this->proposal->fresh()->status)->toBe(ProposalStatus::Draft)
        ->and(ProposalReview::query()->count())->toBe(0);
})->with([
    'oferta inactiva' => [fn () => CareerCampus::query()->update(['is_active' => false])],
    'oferta borrada' => [fn () => CareerCampus::query()->get()->each->delete()],
]);
