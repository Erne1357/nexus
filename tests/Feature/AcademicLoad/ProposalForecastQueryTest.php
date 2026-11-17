<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\ForecastReason;
use App\Modules\AcademicLoad\Enums\SuggestionState;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\HistoricalGroup;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Queries\CareerProposalQuery;
use App\Modules\AcademicLoad\Queries\ProposalForecastQuery;
use App\Modules\AcademicLoad\Queries\ProposalForecastReview;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Demand\Enums\ForecastMethod;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Demand\Rules\DemandSettings;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use Illuminate\Support\Facades\DB;

/*
| ProposalForecastQuery (tarea 5.6, opción C «Revisión de la proyección», elegida por el usuario el 17 de noviembre de 2026):
| el estado de cada fila de la propuesta frente a la proyección vigente (ProposalSuggestionState), sus motivos para revisar
| (la proyección cambió, se aparta del sugerido, especialidad, sin historia) y la evidencia de las tarjetas: el historial de
| los periodos del mismo tipo. Una consulta para la proyección y tres para el historial, sin importar cuántas filas.
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->career = Career::factory()->create(['code' => 'ISC']);
    CareerCampus::factory()->create(['career_id' => $this->career->id, 'campus_id' => $this->c1->id]);
    CareerCampus::factory()->create(['career_id' => $this->career->id, 'campus_id' => $this->c2->id]);
    $this->department = Department::factory()->create(['code' => 'CB']);
    $this->plan = StudyPlan::factory()->create(['career_id' => $this->career->id]);
    $this->proposal = CareerProposal::factory()->create(['term_id' => $this->term->id, 'career_id' => $this->career->id]);
});

/**
 * Una fila de la propuesta en `$campus` y, si `$forecast` no es null, su proyección vigente.
 *
 * @param  array<string, mixed>  $item
 * @param  array<string, mixed>|null  $forecast
 */
function forecastQueryRow(string $code, Campus $campus, array $item = [], ?array $forecast = [], ?Subject $subject = null): ProposalItem
{
    $subject ??= Subject::query()->where('code', $code)->first() ?? Subject::factory()->create(['code' => $code, 'department_id' => test()->department->id]);

    if ($forecast !== null) {
        DemandForecast::factory()->create([
            'term_id' => test()->term->id,
            'modality_id' => test()->proposal->modality_id,
            'career_id' => test()->career->id,
            'subject_id' => $subject->id,
            'campus_id' => $campus->id,
            'expected_students' => 50,
            'suggested_groups' => 2,
            ...$forecast,
        ]);
    }

    return ProposalItem::factory()->create([
        'career_proposal_id' => test()->proposal->id,
        'campus_id' => $campus->id,
        'subject_id' => $subject->id,
        'study_plan_id' => test()->plan->id,
        'semester' => 1,
        'eligible_students' => 60,
        'expected_students' => 50,
        'requested_groups' => 2,
        'suggested_expected_students' => 50,
        'suggested_groups' => 2,
        'forecast_method' => ForecastMethod::Formula,
        ...$item,
    ]);
}

function proposalForecastReview(): ProposalForecastReview
{
    $overview = app(CareerProposalQuery::class)->overview(CareerProposal::query()->findOrFail(test()->proposal->id), withPendingCatalogs: false);

    return app(ProposalForecastQuery::class)->review($overview, DemandSettings::fromValues([]));
}

it('da el estado de cada fila y sus motivos para revisar', function () {
    $untouched = forecastQueryRow('ACF-0901', $this->c1);
    $fallback = forecastQueryRow('ACF-0902', $this->c1, forecast: ['method' => ForecastMethod::DepartmentFallback]);
    $small = forecastQueryRow('ACF-0903', $this->c1, ['expected_students' => 54, 'captured_at' => now()]);
    $large = forecastQueryRow('ACF-0904', $this->c1, ['requested_groups' => 3, 'captured_at' => now()]);
    $changed = forecastQueryRow('ACF-0905', $this->c1, ['captured_at' => now()], ['expected_students' => 90, 'suggested_groups' => 3]);

    $review = proposalForecastReview();

    expect($review->row($untouched)->suggestion->state)->toBe(SuggestionState::Suggested)
        ->and($review->row($untouched)->reasons)->toBe([])
        ->and($review->row($fallback)->reasons)->toBe([ForecastReason::NoHistory])
        ->and($review->row($small)->suggestion->state)->toBe(SuggestionState::Captured)
        ->and($review->row($small)->reasons)->toBe([])
        ->and($review->row($large)->reasons)->toBe([ForecastReason::LargeDifference])
        ->and($review->row($changed)->suggestion->state)->toBe(SuggestionState::Changed)
        ->and($review->row($changed)->reasons)->toBe([ForecastReason::Changed, ForecastReason::LargeDifference]);
});

it('una materia de especialidad sin tocar pide revisión; ya corregida, no', function () {
    $specialty = Specialty::factory()->create(['study_plan_id' => $this->plan->id]);
    $mobile = Subject::factory()->create(['code' => 'DWC-2106', 'department_id' => $this->department->id]);
    StudyPlanSubject::factory()->create(['study_plan_id' => $this->plan->id, 'subject_id' => $mobile->id, 'specialty_id' => $specialty->id, 'semester' => 9]);
    $c1 = forecastQueryRow('DWC-2106', $this->c1, subject: $mobile);
    $c2 = forecastQueryRow('DWC-2106', $this->c2, ['expected_students' => 52, 'captured_at' => now()], subject: $mobile);

    $review = proposalForecastReview();

    expect($review->row($c1)->reasons)->toBe([ForecastReason::Specialty])
        ->and($review->row($c2)->reasons)->toBe([]);
});

it('una fila con motivo está pendiente hasta revisarla, salvo un cambio de la proyección, que siempre lo está', function () {
    $reviewed = ['reviewed_at' => now()];
    $large = forecastQueryRow('ACF-0901', $this->c1, ['requested_groups' => 3, 'captured_at' => now(), ...$reviewed]);
    $changed = forecastQueryRow('ACF-0902', $this->c1, ['captured_at' => now(), ...$reviewed], ['expected_students' => 90, 'suggested_groups' => 3]);
    $open = forecastQueryRow('ACF-0903', $this->c1, ['requested_groups' => 4, 'captured_at' => now()]);

    $review = proposalForecastReview();
    $ids = fn (array $rows): array => array_map(fn ($row) => $row->item->id, $rows);

    expect($ids($review->pending([$this->c1->id])))->toBe([$changed->id, $open->id])
        ->and($ids($review->reviewed([$this->c1->id])))->toBe([$large->id]);
});

it('cuenta lo pendiente de las columnas pedidas, por motivo', function () {
    forecastQueryRow('ACF-0901', $this->c1, ['requested_groups' => 3, 'captured_at' => now()]);
    forecastQueryRow('ACF-0902', $this->c1, ['captured_at' => now()], ['expected_students' => 90, 'suggested_groups' => 3]);
    forecastQueryRow('ACF-0903', $this->c1, forecast: ['method' => ForecastMethod::NoHistory]);
    forecastQueryRow('ACF-0901', $this->c2, ['requested_groups' => 3, 'captured_at' => now()]);

    $review = proposalForecastReview();

    expect($review->counts([$this->c1->id]))->toBe(['' => 3, 'cambio' => 1, 'aparta' => 2, 'especialidad' => 0, 'sin-historia' => 1])
        ->and($review->counts([$this->c2->id])[''])->toBe(1)
        ->and($review->changed([$this->c1->id]))->toBe(1);
});

it('sin proyección para la propuesta no hay nada que revisar', function () {
    $row = forecastQueryRow('ACF-0901', $this->c1, ['requested_groups' => 4, 'captured_at' => now()], null);

    $review = proposalForecastReview();

    expect($review->hasForecast())->toBeFalse()
        ->and($review->computedAt)->toBeNull()
        ->and($review->pending([$this->c1->id]))->toBe([]);
});

it('dice de cuándo es la proyección, cuántos periodos mira y de qué tipo, y el tamaño de los grupos', function () {
    forecastQueryRow('ACF-0901', $this->c1, forecast: ['computed_at' => '2026-11-17 06:10:00']);
    forecastQueryRow('ACF-0902', $this->c1, forecast: ['computed_at' => '2026-11-16 06:10:00']);

    expect(proposalForecastReview()->headerLine('America/Ciudad_Juarez'))->toBe('Proyección de Nexus del 17/11/2026 a las 6:10 · últimos 4 Ene-Jun · 35 por grupo, 25 con laboratorio');
});

it('la proyección se lee en una sola consulta, sin importar cuántas filas', function () {
    $count = function (): int {
        $overview = app(CareerProposalQuery::class)->overview(CareerProposal::query()->findOrFail($this->proposal->id), withPendingCatalogs: false);
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ProposalForecastQuery::class)->review($overview, DemandSettings::fromValues([]));
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    forecastQueryRow('ACF-0901', $this->c1);

    expect($count())->toBe(1);

    foreach (range(2, 9) as $n) {
        forecastQueryRow("ACF-090{$n}", $this->c1, ['captured_at' => now()], ['expected_students' => 70]);
    }

    expect($count())->toBe(1);
});

it('da el historial de cada fila en su campus: grupos, lugares e inscritos de los grupos, y reprobados del kárdex', function () {
    $row = forecastQueryRow('ACF-0901', $this->c1);
    HistoricalGroup::factory()->fromSii()->create(['subject_id' => $row->subject_id, 'term_code' => '20261', 'campus_id' => $this->c1->id, 'group_code' => 'A', 'capacity' => 40, 'enrolled' => 38]);
    HistoricalGroup::factory()->fromSii()->create(['subject_id' => $row->subject_id, 'term_code' => '20261', 'campus_id' => $this->c1->id, 'group_code' => 'B', 'capacity' => 35, 'enrolled' => 30]);
    HistoricalGroup::factory()->fromSii()->create(['subject_id' => $row->subject_id, 'term_code' => '20261', 'campus_id' => $this->c2->id, 'group_code' => 'C', 'capacity' => 99, 'enrolled' => 99]);
    $student = fn (Campus $campus) => Student::factory()->create(['campus_id' => $campus->id, 'modality_id' => $this->proposal->modality_id]);
    KardexRecord::factory()->failed()->create(['student_id' => $student($this->c1)->id, 'subject_id' => $row->subject_id, 'term_code' => '20261']);
    KardexRecord::factory()->failed()->create(['student_id' => $student($this->c1)->id, 'subject_id' => $row->subject_id, 'term_code' => '20241']);
    KardexRecord::factory()->create(['student_id' => $student($this->c1)->id, 'subject_id' => $row->subject_id, 'term_code' => '20261']);
    KardexRecord::factory()->failed()->create(['student_id' => $student($this->c2)->id, 'subject_id' => $row->subject_id, 'term_code' => '20261']);

    $review = proposalForecastReview();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $history = app(ProposalForecastQuery::class)->history($review, [$review->row($row)]);
    DB::disableQueryLog();

    expect(count(DB::getQueryLog()))->toBe(3)
        ->and($history[$row->id])->toBe([
            ['term' => '20231', 'label' => '2023', 'groups' => null, 'capacity' => null, 'enrolled' => null, 'failed' => null],
            ['term' => '20241', 'label' => '2024', 'groups' => null, 'capacity' => null, 'enrolled' => null, 'failed' => 1],
            ['term' => '20251', 'label' => '2025', 'groups' => null, 'capacity' => null, 'enrolled' => null, 'failed' => null],
            ['term' => '20261', 'label' => '2026', 'groups' => 2, 'capacity' => 75, 'enrolled' => 68, 'failed' => 1],
        ]);
});
