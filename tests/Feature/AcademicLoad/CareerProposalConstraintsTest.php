<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/*
| Integridad de la propuesta de carrera (docs/dominio/modelo-de-datos.md, "AcademicLoad"): una por periodo,
| modalidad y carrera (M6), que nace en borrador y no guarda alumnos posibles negativos; una fila por materia y campus
| (también sin campus, MS5), con semestre desde 1 y conteos no negativos; y
| una parte por departamento, que solo se regresa con comentario.
*/

it('la propuesta nace en borrador y conoce su periodo, modalidad y carrera', function () {
    $proposal = CareerProposal::query()->create([
        'term_id' => Term::factory()->create()->id,
        'modality_id' => modalityOf('PRES')->id,
        'career_id' => Career::factory()->create()->id,
    ])->fresh();

    expect($proposal->status)->toBe(ProposalStatus::Draft)
        ->and($proposal->term)->toBeInstanceOf(Term::class)
        ->and($proposal->modality->code)->toBe('PRES')
        ->and($proposal->career)->toBeInstanceOf(Career::class)
        ->and($proposal->submitted_by)->toBeNull()
        ->and($proposal->submitted_at)->toBeNull();
});

it('M6: una sola propuesta por periodo, modalidad y carrera; cambiando cualquiera de los tres, sí', function () {
    $proposal = CareerProposal::factory()->create();

    CareerProposal::factory()->create([...$proposal->only(['term_id', 'modality_id'])]);
    CareerProposal::factory()->create([...$proposal->only(['term_id', 'career_id']), 'modality_id' => modalityOf('EAD')->id]);
    CareerProposal::factory()->create($proposal->only(['modality_id', 'career_id']));

    expect(fn () => CareerProposal::factory()->create($proposal->only(['term_id', 'modality_id', 'career_id'])))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('la propuesta acepta sus cuatro estados y rechaza otro', function () {
    foreach (ProposalStatus::cases() as $status) {
        CareerProposal::factory()->inStatus($status)->create();
    }

    expect(CareerProposal::query()->pluck('status')->all())->toEqualCanonicalizing(ProposalStatus::cases())
        ->and(fn () => DB::table('career_proposals')->update(['status' => 'cancelled']))
        ->toThrow(QueryException::class, 'career_proposals_status_check');
});

it('la propuesta rechaza alumnos posibles negativos', function () {
    $proposal = CareerProposal::factory()->create();

    expect(fn () => DB::table('career_proposals')->where('id', $proposal->id)->update(['eligible_students' => -1]))
        ->toThrow(QueryException::class, 'career_proposals_eligible_students_check');
});

it('la propuesta enviada guarda quién y cuándo', function () {
    $proposal = CareerProposal::factory()->submitted()->create()->fresh();

    expect($proposal->status)->toBe(ProposalStatus::Submitted)
        ->and($proposal->submitter)->toBeInstanceOf(User::class)
        ->and($proposal->submitted_at)->not->toBeNull();
});

it('la fila conoce su propuesta, materia y plan', function () {
    $item = ProposalItem::factory()->create()->fresh();

    expect($item->proposal)->toBeInstanceOf(CareerProposal::class)
        ->and($item->subject)->toBeInstanceOf(Subject::class)
        ->and($item->studyPlan)->toBeInstanceOf(StudyPlan::class)
        ->and($item->proposal->items->pluck('id')->all())->toBe([$item->id]);
});

it('M6: una sola fila por propuesta, materia y campus; la misma materia en otro campus o en otra propuesta, sí', function () {
    $item = ProposalItem::factory()->create();

    ProposalItem::factory()->create(['subject_id' => $item->subject_id]);
    ProposalItem::factory()->create($item->only(['career_proposal_id', 'subject_id']));

    expect(fn () => ProposalItem::factory()->create($item->only(['career_proposal_id', 'subject_id', 'campus_id'])))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('MS5: la fila sin campus de una modalidad sin salones también es una sola por materia', function () {
    $item = ProposalItem::factory()->create(['campus_id' => null]);

    expect(fn () => ProposalItem::factory()->create([...$item->only(['career_proposal_id', 'subject_id']), 'campus_id' => null]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('rechaza un semestre menor que 1 y conteos negativos', function (string $column, int $value, string $constraint) {
    $item = ProposalItem::factory()->create();

    expect(fn () => DB::table('proposal_items')->where('id', $item->id)->update([$column => $value]))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'semestre 0' => ['semester', 0, 'proposal_items_semester_check'],
    'elegibles negativos' => ['eligible_students', -1, 'proposal_items_eligible_students_check'],
    'esperados negativos' => ['expected_students', -1, 'proposal_items_expected_students_check'],
    'grupos negativos' => ['requested_groups', -1, 'proposal_items_requested_groups_check'],
]);

it('acepta cero grupos y cero alumnos', function () {
    $item = ProposalItem::factory()->withoutGroups()->create(['eligible_students' => 0, 'expected_students' => 0])->fresh();

    expect($item->requested_groups)->toBe(0)
        ->and($item->expected_students)->toBe(0);
});

it('la parte nace pendiente y conoce su propuesta y departamento', function () {
    $review = ProposalReview::query()->create([
        'career_proposal_id' => CareerProposal::factory()->create()->id,
        'department_id' => Department::factory()->create()->id,
    ])->fresh();

    expect($review->status)->toBe(ProposalReviewStatus::Pending)
        ->and($review->proposal)->toBeInstanceOf(CareerProposal::class)
        ->and($review->department)->toBeInstanceOf(Department::class)
        ->and($review->proposal->reviews->pluck('id')->all())->toBe([$review->id]);
});

it('una sola parte por propuesta y departamento', function () {
    $review = ProposalReview::factory()->create();

    ProposalReview::factory()->create(['department_id' => $review->department_id]);

    expect(fn () => ProposalReview::factory()->create($review->only(['career_proposal_id', 'department_id'])))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('la parte acepta sus tres estados y rechaza otro', function () {
    ProposalReview::factory()->create();
    ProposalReview::factory()->accepted()->create();
    ProposalReview::factory()->returned()->create();

    expect(ProposalReview::query()->pluck('status')->all())->toEqualCanonicalizing(ProposalReviewStatus::cases())
        ->and(fn () => DB::table('proposal_reviews')->update(['status' => 'rejected']))
        ->toThrow(QueryException::class, 'proposal_reviews_status_check');
});

it('la parte aceptada guarda quién decidió y cuándo', function () {
    $review = ProposalReview::factory()->accepted()->create()->fresh();

    expect($review->decider)->toBeInstanceOf(User::class)
        ->and($review->decided_at)->not->toBeNull();
});

it('una parte regresada exige comentario no vacío', function (?string $comment) {
    $review = ProposalReview::factory()->create();

    expect(fn () => DB::table('proposal_reviews')->where('id', $review->id)->update(['status' => 'returned', 'comment' => $comment]))
        ->toThrow(QueryException::class, 'proposal_reviews_returned_comment_check');
})->with([
    'sin comentario' => [null],
    'vacío' => [''],
    'solo espacios' => ['   '],
]);
