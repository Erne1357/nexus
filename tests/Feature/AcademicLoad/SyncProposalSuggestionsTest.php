<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Proposals\SyncProposalSuggestions;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Events\ProposalSuggestionsSynced;
use App\Modules\AcademicLoad\Listeners\SyncProposalsOnForecastsComputed;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Demand\Enums\ForecastMethod;
use App\Modules\Demand\Events\DemandForecastsComputed;
use App\Modules\Demand\Models\DemandForecast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;

/*
| SyncProposalSuggestions (tarea 5.5, confirmado el 16 de noviembre de 2026): al recalcular la proyección, las filas que el
| coordinador no ha tocado siguen solas a la proyección nueva y las que corrigió conservan lo capturado. Una propuesta enviada
| o aceptada no se toca, ni la parte de un departamento que ya aceptó. Una entrada de bitácora por propuesta y consultas que
| no crecen con las filas.
*/

beforeEach(function () {
    $this->term = Term::factory()->create();
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->career = Career::factory()->create(['code' => 'ISC']);
    $this->department = Department::factory()->create(['code' => 'CB']);
    $this->proposal = CareerProposal::factory()->create(['term_id' => $this->term->id, 'career_id' => $this->career->id]);
});

/**
 * Una fila de la propuesta que nació con la proyección (53 esperados y 2 grupos) y su proyección vigente con `$expected`.
 *
 * @param  array<string, mixed>  $item
 */
function syncRowWithForecast(?CareerProposal $proposal = null, ?Campus $campus = null, int $expected = 70, array $item = [], ?Subject $subject = null, int $groups = 2): ProposalItem
{
    $proposal ??= test()->proposal;
    $campus ??= test()->c2;
    $subject ??= Subject::factory()->create(['department_id' => test()->department->id]);

    DemandForecast::factory()->create([
        'term_id' => $proposal->term_id,
        'modality_id' => $proposal->modality_id,
        'career_id' => $proposal->career_id,
        'subject_id' => $subject->id,
        'campus_id' => $campus->id,
        'expected_students' => $expected,
        'suggested_groups' => $groups,
        'method' => ForecastMethod::Formula,
    ]);

    return ProposalItem::factory()->create([
        'career_proposal_id' => $proposal->id,
        'campus_id' => $campus->id,
        'subject_id' => $subject->id,
        'expected_students' => 53,
        'requested_groups' => 2,
        'suggested_expected_students' => 53,
        'suggested_groups' => 2,
        'forecast_method' => ForecastMethod::NoHistory,
        ...$item,
    ]);
}

function syncProposalSuggestions(?string $campusId = null, ?CareerProposal $proposal = null): int
{
    $proposal ??= test()->proposal;

    return app(SyncProposalSuggestions::class)->handle($proposal->term_id, $proposal->modality_id, $campusId, [$proposal->career_id]);
}

it('una fila sin tocar sigue a la proyección y una corregida no', function () {
    Event::fake([ProposalSuggestionsSynced::class]);
    $untouched = syncRowWithForecast();
    $captured = syncRowWithForecast(item: ['expected_students' => 40, 'captured_at' => now()]);

    expect(syncProposalSuggestions())->toBe(1);

    expect($untouched->fresh()->only(['expected_students', 'requested_groups', 'suggested_expected_students', 'suggested_groups']))->toBe([
        'expected_students' => 70, 'requested_groups' => 2, 'suggested_expected_students' => 70, 'suggested_groups' => 2,
    ])
        ->and($untouched->fresh()->forecast_method)->toBe(ForecastMethod::Formula)
        ->and($untouched->fresh()->captured_at)->toBeNull()
        ->and($captured->fresh()->only(['expected_students', 'suggested_expected_students']))->toBe(['expected_students' => 40, 'suggested_expected_students' => 53]);

    Event::assertDispatched(ProposalSuggestionsSynced::class, fn (ProposalSuggestionsSynced $event): bool => $event->count === 1 && $event->proposal->is($this->proposal));
});

it('una fila sin tocar que se queda sin proyección no cambia', function () {
    $item = ProposalItem::factory()->create(['career_proposal_id' => $this->proposal->id, 'campus_id' => $this->c2->id, 'expected_students' => 30, 'requested_groups' => 1]);

    expect(syncProposalSuggestions())->toBe(0)
        ->and($item->fresh()->only(['expected_students', 'requested_groups', 'suggested_groups']))->toBe(['expected_students' => 30, 'requested_groups' => 1, 'suggested_groups' => null]);
});

it('la fila de un departamento que ya aceptó su parte no cambia, la de otro sí', function () {
    $accepted = syncRowWithForecast();
    $other = syncRowWithForecast(subject: Subject::factory()->create(['department_id' => Department::factory()->create()->id]));
    ProposalReview::factory()->accepted()->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $this->department->id]);

    expect(syncProposalSuggestions())->toBe(1)
        ->and($accepted->fresh()->expected_students)->toBe(53)
        ->and($other->fresh()->expected_students)->toBe(70);
});

it('una propuesta enviada o aceptada no se toca, una regresada sí', function (ProposalStatus $status, int $expected) {
    $this->proposal->forceFill(['status' => $status])->save();
    $item = syncRowWithForecast();

    syncProposalSuggestions();

    expect($item->fresh()->expected_students)->toBe($expected);
})->with([
    'enviada' => [ProposalStatus::Submitted, 53],
    'aceptada' => [ProposalStatus::Accepted, 53],
    'regresada' => [ProposalStatus::Returned, 70],
    'borrador' => [ProposalStatus::Draft, 70],
]);

it('una carrera fuera del evento no se toca', function () {
    $other = CareerProposal::factory()->create(['term_id' => $this->term->id, 'modality_id' => $this->proposal->modality_id]);
    $otherItem = syncRowWithForecast($other);
    $item = syncRowWithForecast();

    syncProposalSuggestions();

    expect($item->fresh()->expected_students)->toBe(70)
        ->and($otherItem->fresh()->expected_students)->toBe(53);
});

it('el filtro de campus del evento limita a las filas de ese campus', function () {
    $inC2 = syncRowWithForecast(campus: $this->c2);
    $inC1 = syncRowWithForecast(campus: $this->c1);

    expect(syncProposalSuggestions($this->c1->id))->toBe(1)
        ->and($inC2->fresh()->expected_students)->toBe(53)
        ->and($inC1->fresh()->expected_students)->toBe(70);
});

it('deja una sola entrada de bitácora por propuesta, con la cuenta, y ninguna si nada cambió', function () {
    syncRowWithForecast();
    syncRowWithForecast();
    syncRowWithForecast(campus: $this->c1);

    syncProposalSuggestions();
    syncProposalSuggestions();

    $logs = Activity::query()->where('subject_type', (new CareerProposal)->getMorphClass())->where('subject_id', $this->proposal->id)->get();

    expect($logs)->toHaveCount(1)
        ->and($logs->first()->description)->toContain('Nexus actualizó 3 materias')
        ->and($logs->first()->causer_id)->toBeNull();
});

it('las consultas no crecen con las filas', function () {
    $queries = function (int $rows): int {
        $proposal = CareerProposal::factory()->create(['term_id' => $this->term->id, 'career_id' => Career::factory()->create()->id]);
        foreach (range(1, $rows) as $ignored) {
            syncRowWithForecast($proposal);
        }

        $count = 0;
        DB::listen(function (QueryExecuted $query) use (&$count): void {
            $count++;
        });
        syncProposalSuggestions(proposal: $proposal);

        return $count;
    };

    $few = $queries(2);
    $many = $queries(25);

    expect($many)->toBe($few);
});

it('el listener va en cola y pone al día con los datos del evento', function () {
    expect(is_subclass_of(SyncProposalsOnForecastsComputed::class, ShouldQueue::class))->toBeTrue();

    $item = syncRowWithForecast();

    app(SyncProposalsOnForecastsComputed::class)->handle(new DemandForecastsComputed($this->term->id, $this->proposal->modality_id, null, [$this->career->id]));

    expect($item->fresh()->expected_students)->toBe(70);
});

it('el evento de Demand llega al listener registrado', function () {
    $item = syncRowWithForecast();

    DemandForecastsComputed::dispatch($this->term->id, $this->proposal->modality_id, $this->c2->id, [$this->career->id]);

    expect($item->fresh()->expected_students)->toBe(70);
});
