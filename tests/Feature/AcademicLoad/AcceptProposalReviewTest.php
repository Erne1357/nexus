<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\Proposals\AcceptProposalReview;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Events\LoadTransitioned;
use App\Modules\AcademicLoad\Events\LoadTransitioning;
use App\Modules\AcademicLoad\Events\ProposalReviewAccepted;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\ModalityProfile;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| AcceptProposalReview: el departamento acepta su parte de una propuesta de carrera (reglas-de-negocio.md,
| "Propuesta de carrera"). Aceptar exige los catálogos del departamento validados (HC-14); la propuesta queda
| aceptada cuando todas sus partes lo están, y la carga del departamento en el campus pasa de borrador a
| propuesta con la primera parte que acepta (confirmado el 2 de noviembre de 2026). Solo se decide una parte
| pendiente, con la propuesta enviada o regresada por otro departamento; aceptada con la propuesta regresada, queda
| congelada para el coordinador (confirmado el 2 de noviembre de 2026). Sin la carga del departamento no se acepta
| (supuesto por confirmar).
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->term = Term::factory()->create(['code' => '20271']);
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->cb = Department::factory()->create(['code' => 'CB']);
    $this->sc = Department::factory()->create(['code' => 'SC']);
    $this->proposal = CareerProposal::factory()->submitted()->create([
        'term_id' => $this->term->id,
        'modality_id' => modalityOf('PRES')->id,
        'career_id' => Career::factory()->create(['code' => 'ISC'])->id,
    ]);
    $this->part = ProposalReview::factory()->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $this->cb->id]);
    $this->otherPart = ProposalReview::factory()->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $this->sc->id]);
    $this->load = AcademicLoad::factory()->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('PRES')->id, 'department_id' => $this->cb->id]);
    validateAcceptCatalogs($this->cb);
});

/**
 * Valida los cuatro catálogos generales del departamento en el periodo de la prueba.
 */
function validateAcceptCatalogs(Department $department): void
{
    foreach (Catalog::general() as $catalog) {
        CatalogReview::factory()->general($catalog)->validated()->create(['term_id' => test()->term->id, 'department_id' => $department->id]);
    }
}

function acceptPart(?ProposalReview $part = null): ProposalReview
{
    return app(AcceptProposalReview::class)->handle($part ?? test()->part, test()->actor);
}

/**
 * Los errores de la ValidationException al aceptar, o null si no lanza.
 *
 * @return array<string, list<string>>|null
 */
function acceptErrors(?ProposalReview $part = null): ?array
{
    try {
        acceptPart($part);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return null;
}

it('acepta la parte con los catálogos validados: queda aceptada, con quién y cuándo, y deja bitácora', function () {
    $part = acceptPart();

    expect($part->fresh()->status)->toBe(ProposalReviewStatus::Accepted)
        ->and($part->decided_by)->toBe($this->actor->id)
        ->and($part->decided_at)->not->toBeNull()
        ->and($part->comment)->toBeNull()
        ->and($this->proposal->fresh()->status)->toBe(ProposalStatus::Submitted)
        ->and($this->otherPart->fresh()->status)->toBe(ProposalReviewStatus::Pending);

    $activity = Activity::query()->where('event', 'accepted')->sole();
    expect($activity->subject_id)->toBe($this->part->id)
        ->and($activity->causer_id)->toBe($this->actor->id)
        ->and($activity->attribute_changes['old'])->toBe(['status' => 'pending'])
        ->and($activity->attribute_changes['attributes'])->toBe(['status' => 'accepted'])
        ->and($activity->properties->all())->toBe([
            'career_proposal_id' => $this->proposal->id,
            'proposal_status' => ['old' => 'submitted', 'attributes' => 'submitted'],
        ])
        ->and($activity->description)->toBe('Aceptó la parte de CB en la propuesta de ISC en Presencial para el periodo 20271');
});

it('AZ-01: quien envió la propuesta acepta su parte y la bitácora lo marca', function () {
    $this->proposal->update(['submitted_by' => $this->actor->id]);

    acceptPart();

    $activity = Activity::query()->where('event', 'accepted')->sole();
    expect($this->part->fresh()->status)->toBe(ProposalReviewStatus::Accepted)
        ->and($activity->properties['self_approval'])->toBeTrue();
});

it('con la primera parte aceptada la carga del departamento pasa de borrador a propuesta, con su historial', function () {
    $scLoad = AcademicLoad::factory()->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('PRES')->id, 'department_id' => $this->sc->id]);

    acceptPart();

    $transition = $this->load->transitions()->sole();
    expect($this->load->fresh()->status)->toBe(LoadStatus::Proposed)
        ->and($transition->from_status)->toBe(LoadStatus::Draft)
        ->and($transition->to_status)->toBe(LoadStatus::Proposed)
        ->and($transition->user_id)->toBe($this->actor->id)
        ->and($transition->comment)->toBe('Aceptó su parte de la propuesta de ISC.')
        ->and(Activity::query()->where('event', 'transitioned')->sole()->subject_id)->toBe($this->load->id)
        ->and($scLoad->fresh()->status)->toBe(LoadStatus::Draft);
});

it('HC-14: aceptar queda bloqueado sin los catálogos del departamento validados y no cambia nada', function () {
    CatalogReview::query()->where('department_id', $this->cb->id)->where('catalog', Catalog::StudyPlans)->update(['status' => 'pending']);

    expect(acceptErrors())->toBe(['catalogs' => ['HC-14: faltan por validar los catálogos de Planes de estudio.']])
        ->and($this->part->fresh()->status)->toBe(ProposalReviewStatus::Pending)
        ->and($this->part->fresh()->decided_by)->toBeNull()
        ->and($this->load->fresh()->status)->toBe(LoadStatus::Draft)
        ->and($this->load->transitions()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
});

it('HC-14: también bloquea con la carga ya en propuesta', function () {
    $this->load->update(['status' => LoadStatus::Proposed]);
    CatalogReview::query()->where('department_id', $this->cb->id)->update(['status' => 'pending']);

    expect(acceptErrors())->toBe(['catalogs' => ['HC-14: faltan por validar los catálogos de Docentes, Materias, Planes de estudio y Carreras.']])
        ->and($this->part->fresh()->status)->toBe(ProposalReviewStatus::Pending);
});

it('HC-14: en una modalidad que usa salones exige también los salones del departamento', function () {
    modalityOf('PRES')->update(['settings' => ModalityProfile::DEFAULTS]);
    CatalogReview::factory()->rooms()->create(['term_id' => $this->term->id, 'department_id' => $this->cb->id]);

    expect(acceptErrors())->toBe(['catalogs' => ['HC-14: faltan por validar los catálogos de Salones.']]);

    CatalogReview::query()->where('department_id', $this->cb->id)->where('catalog', Catalog::Rooms)->update(['status' => 'validated']);

    expect(acceptPart()->status)->toBe(ProposalReviewStatus::Accepted);
});

it('con la carga ya fuera de borrador solo acepta: no hay otra transición', function (LoadStatus $status) {
    $this->load->update(['status' => $status]);

    acceptPart();

    expect($this->part->fresh()->status)->toBe(ProposalReviewStatus::Accepted)
        ->and($this->load->fresh()->status)->toBe($status)
        ->and($this->load->transitions()->count())->toBe(0);
})->with([LoadStatus::Proposed, LoadStatus::Staffing]);

it('la segunda parte que acepta el departamento, de otra carrera, no vuelve a transitar la carga', function () {
    acceptPart();
    $another = ProposalReview::factory()->create([
        'career_proposal_id' => CareerProposal::factory()->submitted()->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('PRES')->id])->id,
        'department_id' => $this->cb->id,
    ]);

    acceptPart($another);

    expect($another->fresh()->status)->toBe(ProposalReviewStatus::Accepted)
        ->and($this->load->fresh()->status)->toBe(LoadStatus::Proposed)
        ->and($this->load->transitions()->count())->toBe(1);
});

it('cuando todas las partes quedan aceptadas la propuesta queda aceptada, y el aviso lo dice', function () {
    Event::fake([ProposalReviewAccepted::class]);
    $this->otherPart->update(['status' => ProposalReviewStatus::Accepted, 'decided_by' => User::factory()->create()->id, 'decided_at' => now()]);

    acceptPart();

    expect($this->proposal->fresh()->status)->toBe(ProposalStatus::Accepted)
        ->and(Activity::query()->where('event', 'accepted')->sole()->properties['proposal_status'])->toBe(['old' => 'submitted', 'attributes' => 'accepted']);
    Event::assertDispatched(ProposalReviewAccepted::class, fn (ProposalReviewAccepted $event): bool => $event->proposalAccepted);
});

describe('con la propuesta regresada por otro departamento', function () {
    beforeEach(function () {
        $this->proposal->update(['status' => ProposalStatus::Returned]);
        $this->otherPart->update(['status' => ProposalReviewStatus::Returned, 'comment' => 'Pide un grupo más de Redes.', 'decided_by' => User::factory()->create()->id, 'decided_at' => now()]);
    });

    it('acepta la parte pendiente, la propuesta sigue regresada y la carga pasa a propuesta', function () {
        Event::fake([ProposalReviewAccepted::class]);

        $part = acceptPart();

        expect($part->fresh()->status)->toBe(ProposalReviewStatus::Accepted)
            ->and($part->decided_by)->toBe($this->actor->id)
            ->and($this->proposal->fresh()->status)->toBe(ProposalStatus::Returned)
            ->and($this->otherPart->fresh())->status->toBe(ProposalReviewStatus::Returned)->comment->toBe('Pide un grupo más de Redes.')
            ->and($this->load->fresh()->status)->toBe(LoadStatus::Proposed)
            ->and(Activity::query()->where('event', 'accepted')->sole()->properties['proposal_status'])->toBe(['old' => 'returned', 'attributes' => 'returned']);
        Event::assertDispatched(ProposalReviewAccepted::class, fn (ProposalReviewAccepted $event): bool => $event->proposalAccepted === false);
    });

    it('HC-14: también exige los catálogos del departamento validados', function () {
        CatalogReview::query()->where('department_id', $this->cb->id)->where('catalog', Catalog::Subjects)->update(['status' => 'pending']);

        expect(acceptErrors())->toBe(['catalogs' => ['HC-14: faltan por validar los catálogos de Materias.']])
            ->and($this->part->fresh()->status)->toBe(ProposalReviewStatus::Pending)
            ->and($this->load->fresh()->status)->toBe(LoadStatus::Draft);
    });
});

it('no decide una parte ya aceptada o regresada, con la propuesta enviada o regresada', function (string $state, string $message, ProposalStatus $proposalStatus) {
    $this->proposal->update(['status' => $proposalStatus]);
    $this->part->update(match ($state) {
        'accepted' => ['status' => ProposalReviewStatus::Accepted, 'decided_by' => $this->actor->id, 'decided_at' => now()],
        'returned' => ['status' => ProposalReviewStatus::Returned, 'comment' => 'Falta un grupo.', 'decided_by' => $this->actor->id, 'decided_at' => now()],
    });

    expect(acceptErrors())->toBe(['status' => [$message]])
        ->and($this->part->fresh()->status->value)->toBe($state)
        ->and($this->load->fresh()->status)->toBe(LoadStatus::Draft);
})->with([
    'aceptada' => ['accepted', 'CB ya aceptó su parte.'],
    'regresada' => ['returned', 'CB ya regresó su parte.'],
])->with([
    'enviada' => [ProposalStatus::Submitted],
    'regresada' => [ProposalStatus::Returned],
]);

it('no decide con la propuesta en borrador o aceptada', function (ProposalStatus $status, string $message) {
    $this->proposal->update(['status' => $status]);

    expect(acceptErrors())->toBe(['status' => [$message]])
        ->and($this->part->fresh()->status)->toBe(ProposalReviewStatus::Pending)
        ->and($this->load->fresh()->status)->toBe(LoadStatus::Draft);
})->with([
    'borrador' => [ProposalStatus::Draft, 'La propuesta todavía no se envía.'],
    'aceptada' => [ProposalStatus::Accepted, 'La propuesta ya se aceptó.'],
]);

it('usa el estado de la base y no el de los modelos que llegaron', function () {
    $stale = ProposalReview::query()->findOrFail($this->part->id);
    DB::table('proposal_reviews')->where('id', $this->part->id)->update(['status' => 'accepted']);

    expect(acceptErrors($stale))->toBe(['status' => ['CB ya aceptó su parte.']]);

    DB::table('proposal_reviews')->where('id', $this->part->id)->update(['status' => 'pending']);
    DB::table('career_proposals')->where('id', $this->proposal->id)->update(['status' => 'draft']);

    expect(acceptErrors($stale))->toBe(['status' => ['La propuesta todavía no se envía.']]);
});

it('M4: sin la carga del departamento en el campus no acepta y dice que se complete la apertura en su modalidad', function () {
    $this->load->delete();

    expect(acceptErrors())->toBe(['load' => ['CB no tiene carga en Presencial para el periodo 20271: completa la apertura del periodo en Presencial para crearla.']])
        ->and($this->part->fresh()->status)->toBe(ProposalReviewStatus::Pending);
});

it('si la carga no puede salir de borrador no acepta nada', function () {
    Event::listen(LoadTransitioning::class, fn (LoadTransitioning $event) => $event->block('Hay grupos sin docente.'));

    expect(acceptErrors())->toBe(['status' => ['Hay grupos sin docente.']])
        ->and($this->part->fresh()->status)->toBe(ProposalReviewStatus::Pending)
        ->and($this->load->fresh()->status)->toBe(LoadStatus::Draft)
        ->and(Activity::query()->count())->toBe(0);
});

it('bloquea la propuesta, luego la parte y luego la carga', function () {
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });

    acceptPart();

    $locked = collect($sql)
        ->filter(fn (string $s): bool => str_ends_with($s, 'for update'))
        ->map(fn (string $s): string => preg_match('/from "(\w+)"/', $s, $match) ? $match[1] : $s)
        ->values()
        ->all();
    expect(array_slice($locked, 0, 3))->toBe(['career_proposals', 'proposal_reviews', 'academic_loads']);
});

it('avisa con ProposalReviewAccepted solo después del commit, y si la propuesta quedó aceptada', function () {
    Event::fake([ProposalReviewAccepted::class]);

    acceptPart();

    Event::assertDispatched(ProposalReviewAccepted::class, fn (ProposalReviewAccepted $event): bool => $event->review->is($this->part)
        && $event->proposalAccepted === false);
});

it('no avisa ni transita si la transacción externa se revierte', function () {
    Event::fake([ProposalReviewAccepted::class, LoadTransitioned::class]);

    try {
        DB::transaction(function () {
            acceptPart();

            throw new RuntimeException('revertir');
        });
    } catch (RuntimeException) {
    }

    Event::assertNotDispatched(ProposalReviewAccepted::class);
    Event::assertNotDispatched(LoadTransitioned::class);
    expect($this->part->fresh()->status)->toBe(ProposalReviewStatus::Pending)
        ->and($this->load->fresh()->status)->toBe(LoadStatus::Draft);
});

it('si la carga no puede proponerse, el modelo recibido no queda aceptado en memoria', function () {
    Event::listen(LoadTransitioning::class, fn (LoadTransitioning $event) => $event->block('Hay grupos sin docente.'));

    acceptErrors();

    expect($this->part->status)->toBe(ProposalReviewStatus::Pending)
        ->and($this->part->decided_by)->toBeNull();
});
