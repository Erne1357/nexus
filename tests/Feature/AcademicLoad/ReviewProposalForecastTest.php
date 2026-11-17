<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Proposals\MarkProposalItemReviewed;
use App\Modules\AcademicLoad\Actions\Proposals\ResolveForecastChange;
use App\Modules\AcademicLoad\Actions\Proposals\UpdateProposalItem;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Events\ProposalItemReviewed;
use App\Modules\AcademicLoad\Events\ProposalItemUpdated;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Demand\Enums\ForecastMethod;
use App\Modules\Demand\Models\DemandForecast;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| «Revisar proyección» (tarea 5.6, opción C elegida por el usuario el 17 de noviembre de 2026): una fila con motivo se marca
| revisada (`reviewed_at`, `reviewed_by`) con «Está bien» o «Dejar» (MarkProposalItemReviewed), con «Usar sugerido»
| (UpdateProposalItem::useSuggested) y con «Usar N» o «Mantener» (ResolveForecastChange). Corregir esperados o grupos la
| vuelve a dejar pendiente (supuesto por confirmar). Con las guardas de UpdateProposalItem.
*/

beforeEach(function () {
    $this->actor = User::factory()->create(['name' => 'Héctor Villalobos']);
    $this->department = Department::factory()->create(['code' => 'CB']);
    $this->campus = Campus::factory()->create(['code' => 'C1']);
    $this->proposal = CareerProposal::factory()->create(['career_id' => Career::factory()->create(['code' => 'ISC'])->id]);
    $this->subject = Subject::factory()->create(['code' => 'ACF-0901', 'department_id' => $this->department->id]);
    $this->item = ProposalItem::factory()->create([
        'career_proposal_id' => $this->proposal->id,
        'campus_id' => $this->campus->id,
        'subject_id' => $this->subject->id,
        'eligible_students' => 60,
        'expected_students' => 68,
        'requested_groups' => 3,
        'suggested_expected_students' => 68,
        'suggested_groups' => 2,
        'forecast_method' => ForecastMethod::Formula,
        'captured_at' => now()->subDay(),
        'captured_by' => $this->actor->id,
    ]);
});

/**
 * Los errores de la ValidationException que lanza `$review`, o null si no lanza.
 *
 * @return array<string, list<string>>|null
 */
function reviewForecastErrors(Closure $review): ?array
{
    try {
        $review();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return null;
}

function reviewForecastFor(ProposalItem $item, int $expected, int $groups): DemandForecast
{
    $proposal = $item->proposal;

    return DemandForecast::factory()->create([
        'term_id' => $proposal->term_id,
        'modality_id' => $proposal->modality_id,
        'career_id' => $proposal->career_id,
        'subject_id' => $item->subject_id,
        'campus_id' => $item->campus_id,
        'expected_students' => $expected,
        'suggested_groups' => $groups,
    ]);
}

describe('MarkProposalItemReviewed', function () {
    it('marca la fila revisada con quién y cuándo, deja bitácora y avisa después del commit', function () {
        Event::fake([ProposalItemReviewed::class, ProposalItemUpdated::class]);

        app(MarkProposalItemReviewed::class)->handle($this->item, $this->actor);

        $fresh = $this->item->fresh();
        $activity = Activity::query()->where('subject_id', $this->item->id)->sole();

        expect($fresh->reviewed_at)->not->toBeNull()
            ->and($fresh->reviewed_by)->toBe($this->actor->id)
            ->and($fresh->only(['expected_students', 'requested_groups']))->toBe(['expected_students' => 68, 'requested_groups' => 3])
            ->and($activity->causer_id)->toBe($this->actor->id)
            ->and($activity->description)->toBe('Revisó la proyección de ACF-0901 de C1 en la propuesta de ISC en Presencial')
            ->and(array_keys($activity->attribute_changes['attributes']))->toBe(['reviewed_at', 'reviewed_by'])
            ->and($activity->attribute_changes['old'])->toBe(['reviewed_at' => null, 'reviewed_by' => null]);

        Event::assertDispatched(ProposalItemReviewed::class, fn (ProposalItemReviewed $event): bool => $event->item->is($this->item));
        Event::assertNotDispatched(ProposalItemUpdated::class);
    });

    it('una fila ya revisada no se escribe ni se registra otra vez', function () {
        $this->item->forceFill(['reviewed_at' => now()->subHour(), 'reviewed_by' => $this->actor->id])->save();
        Event::fake([ProposalItemReviewed::class]);

        app(MarkProposalItemReviewed::class)->handle($this->item, $this->actor);

        expect(Activity::query()->where('subject_id', $this->item->id)->count())->toBe(0);
        Event::assertNotDispatched(ProposalItemReviewed::class);
    });

    it('no marca en una propuesta enviada ni aceptada', function (ProposalStatus $status) {
        $this->proposal->forceFill(['status' => $status])->save();

        expect(reviewForecastErrors(fn () => app(MarkProposalItemReviewed::class)->handle($this->item, $this->actor)))->toHaveKey('status')
            ->and($this->item->fresh()->reviewed_at)->toBeNull();
    })->with([ProposalStatus::Submitted, ProposalStatus::Accepted]);

    it('no marca la fila de un departamento que ya aceptó su parte', function () {
        ProposalReview::factory()->accepted()->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $this->department->id]);

        expect(reviewForecastErrors(fn () => app(MarkProposalItemReviewed::class)->handle($this->item, $this->actor)))->toHaveKey('item')
            ->and($this->item->fresh()->reviewed_at)->toBeNull();
    });
});

describe('Usar sugerido', function () {
    it('pone esperados y grupos en lo sugerido, la deja corregida y revisada, y registra el cambio', function () {
        app(UpdateProposalItem::class)->useSuggested($this->item, $this->actor);

        $fresh = $this->item->fresh();
        $activity = Activity::query()->where('subject_id', $this->item->id)->sole();

        expect($fresh->only(['expected_students', 'requested_groups']))->toBe(['expected_students' => 68, 'requested_groups' => 2])
            ->and($fresh->captured_by)->toBe($this->actor->id)
            ->and($fresh->captured_at->isAfter(now()->subMinute()))->toBeTrue()
            ->and($fresh->reviewed_by)->toBe($this->actor->id)
            ->and($fresh->reviewed_at)->not->toBeNull()
            ->and($activity->attribute_changes->toArray())->toBe(['attributes' => ['requested_groups' => 2], 'old' => ['requested_groups' => 3]]);
    });

    it('sin nada sugerido es una ValidationException sobre item', function () {
        $this->item->forceFill(['suggested_expected_students' => null, 'suggested_groups' => null])->save();

        expect(reviewForecastErrors(fn () => app(UpdateProposalItem::class)->useSuggested($this->item, $this->actor)))->toHaveKey('item')
            ->and($this->item->fresh()->requested_groups)->toBe(3);
    });

    it('con la propuesta enviada no cambia', function () {
        $this->proposal->forceFill(['status' => ProposalStatus::Submitted])->save();

        expect(reviewForecastErrors(fn () => app(UpdateProposalItem::class)->useSuggested($this->item, $this->actor)))->toHaveKey('status');
    });
});

describe('se vuelve a revisar', function () {
    beforeEach(function () {
        $this->item->forceFill(['reviewed_at' => now()->subHour(), 'reviewed_by' => $this->actor->id])->save();
    });

    it('al corregir esperados o grupos, la revisión se borra', function () {
        app(UpdateProposalItem::class)->handle($this->item, 70, 3, null, $this->actor);

        expect($this->item->fresh()->only(['reviewed_at', 'reviewed_by']))->toBe(['reviewed_at' => null, 'reviewed_by' => null]);
    });

    it('la nota sola no la borra, ni aparece en la bitácora', function () {
        app(UpdateProposalItem::class)->handle($this->item, 68, 3, 'Va con laboratorio.', $this->actor);

        expect($this->item->fresh()->reviewed_by)->toBe($this->actor->id)
            ->and(array_keys(Activity::query()->where('subject_id', $this->item->id)->sole()->attribute_changes['attributes']))->toBe(['notes']);
    });
});

describe('ResolveForecastChange', function () {
    it('«Usar N» y «Mantener» también la marcan revisada', function (string $choice) {
        $this->item->forceFill(['reviewed_at' => now()->subWeek(), 'reviewed_by' => null])->save();
        reviewForecastFor($this->item, 90, 3);

        app(ResolveForecastChange::class)->handle($this->item, $choice, $this->actor);

        $fresh = $this->item->fresh();

        expect($fresh->reviewed_by)->toBe($this->actor->id)
            ->and($fresh->reviewed_at->isAfter(now()->subMinute()))->toBeTrue()
            ->and(array_keys(Activity::query()->where('subject_id', $this->item->id)->sole()->attribute_changes['attributes']))->not->toContain('reviewed_at');
    })->with([ResolveForecastChange::USE, ResolveForecastChange::KEEP]);
});
