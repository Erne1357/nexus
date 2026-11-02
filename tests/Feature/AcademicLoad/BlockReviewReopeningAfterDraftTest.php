<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\Transitions\ProposeLoad;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\CatalogValidation\Actions\ReopenReview;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Validation\ValidationException;

/*
| AcademicLoad veta reabrir una revisión validada (CatalogReviewReopening) si alguna carga del departamento en
| el periodo ya salió de borrador: en cualquier campus si la revisión es general, en el campus de la revisión
| si son salones (CV5, confirmado el 2 de noviembre de 2026).
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->term = Term::factory()->create();
    $this->department = Department::factory()->create(['code' => 'SIS']);
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
});

function reopeningLoad(Campus $campus, LoadStatus $status, array $attributes = []): AcademicLoad
{
    return AcademicLoad::factory()->create([
        'term_id' => test()->term->id,
        'campus_id' => $campus->id,
        'department_id' => test()->department->id,
        'status' => $status,
        ...$attributes,
    ]);
}

function reopeningReview(Catalog $catalog, ?Campus $campus = null): CatalogReview
{
    $factory = $catalog === Catalog::Rooms ? CatalogReview::factory()->rooms($campus) : CatalogReview::factory()->general($catalog);

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

it('CV5: veta reabrir una revisión general si una carga del departamento salió de borrador en cualquier campus', function () {
    reopeningLoad($this->c1, LoadStatus::Draft);
    reopeningLoad($this->c2, LoadStatus::Proposed);
    $review = reopeningReview(Catalog::Subjects);

    expect(reopeningErrors($review))->toBe(['status' => ['La carga académica de SIS ya salió de borrador en C2.']])
        ->and($review->fresh()->status)->toBe(ReviewStatus::Validated);
});

it('CV5: nombra todos los campus donde la carga ya salió de borrador', function () {
    reopeningLoad($this->c2, LoadStatus::Staffing);
    reopeningLoad($this->c1, LoadStatus::Proposed);

    expect(reopeningErrors(reopeningReview(Catalog::Careers)))->toBe(['status' => ['La carga académica de SIS ya salió de borrador en C1 y C2.']]);
});

it('CV5: con todas las cargas del departamento en borrador, se reabre', function () {
    reopeningLoad($this->c1, LoadStatus::Draft);
    reopeningLoad($this->c2, LoadStatus::Draft);
    $review = reopeningReview(Catalog::Teachers);

    app(ReopenReview::class)->handle($review, $this->actor);

    expect($review->fresh()->status)->toBe(ReviewStatus::InReview);
});

it('CV5: en salones solo cuenta la carga del campus de la revisión', function () {
    reopeningLoad($this->c2, LoadStatus::Proposed);
    $c1Rooms = reopeningReview(Catalog::Rooms, $this->c1);
    $c2Rooms = reopeningReview(Catalog::Rooms, $this->c2);

    app(ReopenReview::class)->handle($c1Rooms, $this->actor);

    expect($c1Rooms->fresh()->status)->toBe(ReviewStatus::InReview)
        ->and(reopeningErrors($c2Rooms))->toBe(['status' => ['La carga académica de SIS ya salió de borrador en C2.']]);
});

it('no cuentan las cargas de otro departamento ni de otro periodo', function () {
    reopeningLoad($this->c1, LoadStatus::Proposed, ['department_id' => Department::factory()->create()->id]);
    reopeningLoad($this->c1, LoadStatus::Proposed, ['term_id' => Term::factory()->create()->id]);
    $review = reopeningReview(Catalog::StudyPlans);

    app(ReopenReview::class)->handle($review, $this->actor);

    expect($review->fresh()->status)->toBe(ReviewStatus::InReview);
});

describe('en secuencia con ProposeLoad', function () {
    beforeEach(function () {
        $this->load = reopeningLoad($this->c1, LoadStatus::Draft);
        $this->reviews = collect(Catalog::general())->map(fn (Catalog $catalog) => reopeningReview($catalog))
            ->push(reopeningReview(Catalog::Rooms, $this->c1));
    });

    it('si la carga se propuso primero, la revisión ya no se reabre', function () {
        app(ProposeLoad::class)->handle($this->load, $this->actor);

        expect(reopeningErrors($this->reviews->first()))->toBe(['status' => ['La carga académica de SIS ya salió de borrador en C1.']])
            ->and($this->load->fresh()->status)->toBe(LoadStatus::Proposed);
    });

    it('HC-14: si la revisión se reabrió primero, la carga ya no sale de borrador', function () {
        app(ReopenReview::class)->handle($this->reviews->first(), $this->actor);

        expect(fn () => app(ProposeLoad::class)->handle($this->load, $this->actor))
            ->toThrow(ValidationException::class, 'HC-14: faltan por validar los catálogos de Docentes.')
            ->and($this->load->fresh()->status)->toBe(LoadStatus::Draft);
    });
});
