<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\Transitions\ProposeLoad;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\CatalogValidation\Actions\ReopenReview;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Validation\ValidationException;

/*
| AcademicLoad veta reabrir una revisión validada (CatalogReviewReopening) si alguna carga del departamento en
| el periodo ya salió de borrador en cualquier modalidad (CV5, confirmado el 2 de noviembre de 2026; la carga es por
| departamento y modalidad, M1), también la de salones, que es una para todos los campus (decisión 6, confirmada el 12 de
| noviembre de 2026).
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->term = Term::factory()->create();
    $this->department = Department::factory()->create(['code' => 'SIS']);
    $this->presencial = modalityOf('PRES');
    $this->ead = modalityOf('EAD');
});

function reopeningLoad(Modality $modality, LoadStatus $status, array $attributes = []): AcademicLoad
{
    return AcademicLoad::factory()->create([
        'term_id' => test()->term->id,
        'modality_id' => $modality->id,
        'department_id' => test()->department->id,
        'status' => $status,
        ...$attributes,
    ]);
}

function reopeningReview(Catalog $catalog): CatalogReview
{
    $factory = $catalog === Catalog::Rooms ? CatalogReview::factory()->rooms() : CatalogReview::factory()->general($catalog);

    return $factory->validated()->create(['term_id' => test()->term->id, 'department_id' => test()->department->id]);
}

function reopeningErrors(CatalogReview $review): array
{
    try {
        app(ReopenReview::class)->handle($review, test()->actor);
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

it('CV5: veta reabrir una revisión general si una carga del departamento salió de borrador en cualquier modalidad', function () {
    reopeningLoad($this->ead, LoadStatus::Draft);
    reopeningLoad($this->presencial, LoadStatus::Proposed);
    $review = reopeningReview(Catalog::Subjects);

    expect(reopeningErrors($review))->toBe(['status' => ['La carga académica de SIS ya salió de borrador en Presencial.']])
        ->and($review->fresh()->status)->toBe(ReviewStatus::Validated);
});

it('CV5: nombra todas las modalidades donde la carga ya salió de borrador', function () {
    reopeningLoad($this->presencial, LoadStatus::Staffing);
    reopeningLoad($this->ead, LoadStatus::Proposed);

    expect(reopeningErrors(reopeningReview(Catalog::Careers)))->toBe(['status' => ['La carga académica de SIS ya salió de borrador en EAD y Presencial.']]);
});

it('CV5: con todas las cargas del departamento en borrador, se reabre', function () {
    reopeningLoad($this->presencial, LoadStatus::Draft);
    reopeningLoad($this->ead, LoadStatus::Draft);
    $review = reopeningReview(Catalog::Teachers);

    app(ReopenReview::class)->handle($review, $this->actor);

    expect($review->fresh()->status)->toBe(ReviewStatus::InReview);
});

it('CV5: la de salones, una para todos los campus, se veta si la carga salió de borrador en cualquier modalidad', function () {
    reopeningLoad($this->ead, LoadStatus::Draft);
    reopeningLoad($this->presencial, LoadStatus::Proposed);

    expect(reopeningErrors(reopeningReview(Catalog::Rooms)))->toBe(['status' => ['La carga académica de SIS ya salió de borrador en Presencial.']]);
});

it('no cuentan las cargas de otro departamento ni de otro periodo', function () {
    reopeningLoad($this->presencial, LoadStatus::Proposed, ['department_id' => Department::factory()->create()->id]);
    reopeningLoad($this->presencial, LoadStatus::Proposed, ['term_id' => Term::factory()->create()->id]);
    $review = reopeningReview(Catalog::StudyPlans);

    app(ReopenReview::class)->handle($review, $this->actor);

    expect($review->fresh()->status)->toBe(ReviewStatus::InReview);
});

describe('en secuencia con ProposeLoad', function () {
    beforeEach(function () {
        $this->load = reopeningLoad($this->presencial, LoadStatus::Draft);
        $this->reviews = collect(Catalog::general())->map(fn (Catalog $catalog) => reopeningReview($catalog))
            ->push(reopeningReview(Catalog::Rooms));
        // HC-20: la carga sale de borrador con al menos una parte de propuesta aceptada del departamento.
        ProposalReview::factory()->accepted()->create([
            'career_proposal_id' => CareerProposal::factory()->submitted()->create(['term_id' => $this->load->term_id, 'campus_id' => loadCampus($this->load)->id])->id,
            'department_id' => $this->load->department_id,
        ]);
    });

    it('si la carga se propuso primero, la revisión ya no se reabre', function () {
        app(ProposeLoad::class)->handle($this->load, $this->actor);

        expect(reopeningErrors($this->reviews->first()))->toBe(['status' => ['La carga académica de SIS ya salió de borrador en Presencial.']])
            ->and($this->load->fresh()->status)->toBe(LoadStatus::Proposed);
    });

    it('HC-14: si la revisión se reabrió primero, la carga ya no sale de borrador', function () {
        app(ReopenReview::class)->handle($this->reviews->first(), $this->actor);

        expect(fn () => app(ProposeLoad::class)->handle($this->load, $this->actor))
            ->toThrow(ValidationException::class, 'HC-14: faltan por validar los catálogos de Docentes.')
            ->and($this->load->fresh()->status)->toBe(LoadStatus::Draft);
    });
});
