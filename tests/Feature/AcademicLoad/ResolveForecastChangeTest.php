<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Proposals\ResolveForecastChange;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
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

/*
| ResolveForecastChange (tarea 5.5, confirmado el 16 de noviembre de 2026): el coordinador decide qué hacer con el aviso «la
| proyección cambió: ahora sugiere N» de una fila que corrigió. `use` toma lo nuevo; `keep` conserva lo capturado y acepta lo
| sugerido nuevo. Con las guardas de UpdateProposalItem.
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->department = Department::factory()->create(['code' => 'CB']);
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->proposal = CareerProposal::factory()->create(['career_id' => Career::factory()->create(['code' => 'ISC'])->id]);
    $this->subject = Subject::factory()->create(['code' => 'ACF-0901', 'department_id' => $this->department->id]);
    $this->forecast = DemandForecast::factory()->create([
        'term_id' => $this->proposal->term_id,
        'modality_id' => $this->proposal->modality_id,
        'career_id' => $this->proposal->career_id,
        'subject_id' => $this->subject->id,
        'campus_id' => $this->campus->id,
        'expected_students' => 70,
        'suggested_groups' => 3,
        'method' => ForecastMethod::DepartmentFallback,
    ]);
    $this->item = ProposalItem::factory()->create([
        'career_proposal_id' => $this->proposal->id,
        'campus_id' => $this->campus->id,
        'subject_id' => $this->subject->id,
        'expected_students' => 40,
        'requested_groups' => 2,
        'suggested_expected_students' => 53,
        'suggested_groups' => 2,
        'forecast_method' => ForecastMethod::Formula,
        'captured_at' => now()->subDay(),
        'captured_by' => $this->actor->id,
    ]);
});

/**
 * @return array<string, list<string>>|null
 */
function resolveForecastErrors(Closure $resolve): ?array
{
    try {
        $resolve();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return null;
}

it('use: esperados y grupos pasan a lo nuevo, suggested_* se actualiza y la fila sigue corregida', function () {
    Event::fake([ProposalItemUpdated::class]);

    app(ResolveForecastChange::class)->handle($this->item, 'use', $this->actor);

    $fresh = $this->item->fresh();

    expect($fresh->only(['expected_students', 'requested_groups', 'suggested_expected_students', 'suggested_groups']))->toBe([
        'expected_students' => 70, 'requested_groups' => 3, 'suggested_expected_students' => 70, 'suggested_groups' => 3,
    ])
        ->and($fresh->forecast_method)->toBe(ForecastMethod::DepartmentFallback)
        ->and($fresh->captured_at)->not->toBeNull();

    Event::assertDispatched(ProposalItemUpdated::class, fn (ProposalItemUpdated $event): bool => in_array('expected_students', $event->changed, true) && in_array('suggested_groups', $event->changed, true));
});

it('keep: conserva lo capturado y solo actualiza suggested_*', function () {
    app(ResolveForecastChange::class)->handle($this->item, 'keep', $this->actor);

    expect($this->item->fresh()->only(['expected_students', 'requested_groups', 'suggested_expected_students', 'suggested_groups']))->toBe([
        'expected_students' => 40, 'requested_groups' => 2, 'suggested_expected_students' => 70, 'suggested_groups' => 3,
    ]);
});

it('deja de avisar al resolver: una segunda vez ya no hay cambio pendiente', function () {
    app(ResolveForecastChange::class)->handle($this->item, 'keep', $this->actor);

    expect(resolveForecastErrors(fn () => app(ResolveForecastChange::class)->handle($this->item, 'use', $this->actor)))->toHaveKey('item');
});

it('sin cambio pendiente es una ValidationException sobre item, también en una fila sin tocar', function () {
    $this->item->update(['captured_at' => null]);

    expect(resolveForecastErrors(fn () => app(ResolveForecastChange::class)->handle($this->item, 'use', $this->actor)))->toHaveKey('item');
});

it('relee la proyección vigente dentro de la transacción', function () {
    $this->forecast->update(['expected_students' => 90, 'suggested_groups' => 4]);

    app(ResolveForecastChange::class)->handle($this->item, 'use', $this->actor);

    expect($this->item->fresh()->only(['expected_students', 'requested_groups']))->toBe(['expected_students' => 90, 'requested_groups' => 4]);
});

it('rechaza una elección que no es use ni keep', function () {
    expect(resolveForecastErrors(fn () => app(ResolveForecastChange::class)->handle($this->item, 'other', $this->actor)))->toHaveKey('choice');
});

it('una propuesta enviada o aceptada no cambia', function (ProposalStatus $status) {
    $this->proposal->forceFill(['status' => $status])->save();

    expect(resolveForecastErrors(fn () => app(ResolveForecastChange::class)->handle($this->item, 'use', $this->actor)))->toHaveKey('status')
        ->and($this->item->fresh()->expected_students)->toBe(40);
})->with([ProposalStatus::Submitted, ProposalStatus::Accepted]);

it('la fila de un departamento que ya aceptó su parte no cambia', function () {
    ProposalReview::factory()->accepted()->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $this->department->id]);

    expect(resolveForecastErrors(fn () => app(ResolveForecastChange::class)->handle($this->item, 'use', $this->actor)))->toHaveKey('item')
        ->and($this->item->fresh()->suggested_groups)->toBe(2);
});
