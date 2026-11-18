<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Proposals\MarkProposalItemReviewed;
use App\Modules\AcademicLoad\Enums\ForecastReason;
use App\Modules\AcademicLoad\Enums\SuggestionState;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalIntake;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Queries\CareerProposalQuery;
use App\Modules\AcademicLoad\Queries\ProposalForecastQuery;
use App\Modules\AcademicLoad\Queries\ProposalForecastReview;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Demand\Enums\ForecastMethod;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Demand\Rules\DemandSettings;
use Livewire\Livewire;

/*
| La proyección frente a una fila de 1.º con paquetes (tareas 5.6 y 5.6b): la fila pide los grupos de sus paquetes más los
| sueltos, así que la diferencia grande de grupos se mide contra lo sugerido efectivo de la fila (SuggestedRowGroups: paquetes
| más sueltos sugeridos, con el nuevo ingreso restado en primer semestre), no contra los grupos crudos de la proyección. «Cambió»
| y la diferencia de esperados no cambian. La pantalla y las Actions de «Revisar proyección» lo miden igual.
|
| Cálculo Diferencial en C1: la proyección espera 90 (60 de nuevo ingreso) y sugiere 3 grupos. Con 3 paquetes, lo sugerido
| efectivo es 3 de paquetes + ceil((90 − 60) / 35) = 1 suelto = 4.
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    TermOpening::factory()->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('PRES')->id]);
    $this->career = Career::factory()->create(['code' => 'ISC']);
    CareerCampus::factory()->create(['career_id' => $this->career->id, 'campus_id' => $this->c1->id]);
    $this->department = Department::factory()->create(['code' => 'CB']);
    $this->proposal = CareerProposal::factory()->create(['term_id' => $this->term->id, 'career_id' => $this->career->id]);
    ProposalIntake::factory()->create(['career_proposal_id' => $this->proposal->id, 'campus_id' => $this->c1->id, 'suggested_new_students' => 60, 'new_students' => 60]);
    $this->coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c1->id, 'career_id' => $this->career->id])->create();
});

/**
 * Una fila corregida de primer semestre con `$packageGroups` grupos de paquetes y `$groups` en total, y su proyección
 * (90 esperados y 3 grupos, o lo que diga `$forecast`).
 *
 * @param  array<string, mixed>  $forecast
 */
function forecastPackagesRow(string $code, int $packageGroups, int $groups, array $forecast = []): ProposalItem
{
    $subject = Subject::factory()->create(['code' => $code, 'department_id' => test()->department->id]);

    DemandForecast::factory()->create([
        'term_id' => test()->term->id,
        'modality_id' => test()->proposal->modality_id,
        'career_id' => test()->career->id,
        'subject_id' => $subject->id,
        'campus_id' => test()->c1->id,
        'method' => ForecastMethod::FirstSemester,
        'new_students' => 60,
        'expected_students' => 90,
        'suggested_groups' => 3,
        ...$forecast,
    ]);

    return ProposalItem::factory()->create([
        'career_proposal_id' => test()->proposal->id,
        'campus_id' => test()->c1->id,
        'subject_id' => $subject->id,
        'semester' => 1,
        'eligible_students' => 40,
        'expected_students' => 90,
        'requested_groups' => $groups,
        'package_groups' => $packageGroups,
        'suggested_expected_students' => 90,
        'suggested_groups' => 3,
        'forecast_method' => ForecastMethod::FirstSemester,
        'captured_at' => now(),
    ]);
}

function forecastPackagesReview(): ProposalForecastReview
{
    $overview = app(CareerProposalQuery::class)->overview(CareerProposal::query()->findOrFail(test()->proposal->id), withPendingCatalogs: false);

    return app(ProposalForecastQuery::class)->review($overview, DemandSettings::fromValues([]));
}

it('una fila de 1.º con paquetes que pide sus paquetes más los sueltos sugeridos no se resalta', function () {
    $row = forecastPackagesRow('ACF-0901', packageGroups: 3, groups: 4);

    $review = forecastPackagesReview()->row($row);

    expect($review->suggestion->state)->toBe(SuggestionState::Captured)
        ->and($review->suggestion->largeDifferenceGroups)->toBeFalse()
        ->and($review->reasons)->toBe([])
        ->and($review->effectiveGroups())->toBe(4)
        ->and($review->packageSplit())->toBe(['packages' => 3, 'loose' => 1]);
});

it('con 3 sueltos de más sí se resalta, medido contra lo sugerido efectivo', function () {
    $row = forecastPackagesRow('ACF-0901', packageGroups: 3, groups: 7);

    $review = forecastPackagesReview()->row($row);

    expect($review->reasons)->toBe([ForecastReason::LargeDifference])
        ->and($review->difference())->toBe(['field' => 'groups', 'percent' => '+75 %']);
});

it('«Cambió» sigue funcionando con paquetes', function () {
    $row = forecastPackagesRow('ACF-0901', packageGroups: 3, groups: 4, forecast: ['expected_students' => 100]);

    $review = forecastPackagesReview()->row($row);

    expect($review->suggestion->state)->toBe(SuggestionState::Changed)
        ->and($review->suggestion->newExpected)->toBe(100)
        ->and($review->reasons)->toContain(ForecastReason::Changed);
});

it('las Actions miden igual que la pantalla: «Dejar» vale en una fila que solo se aparta de lo sugerido efectivo', function () {
    $row = forecastPackagesRow('ACF-0901', packageGroups: 1, groups: 3);

    expect(forecastPackagesReview()->row($row)->reasons)->toBe([ForecastReason::LargeDifference]);

    app(MarkProposalItemReviewed::class)->handle($row, $this->coordinator);

    expect($row->fresh()->reviewed_at)->not->toBeNull();
});

it('la tarjeta y el panel dicen lo sugerido efectivo con cuántos son de paquetes y cuántos sueltos', function () {
    $row = forecastPackagesRow('ACF-0901', packageGroups: 3, groups: 7);

    $page = Livewire::withQueryParams(['vista' => 'revisar'])->actingAs($this->coordinator)->test('academic-load::pages.proposal');

    expect(textOf(domOf($page)->querySelector('[data-testid="review-card-'.$row->id.'"] [data-testid="review-what"]')))
        ->toBe('Capturaste 90 esperados y 7 grupos; Nexus sugiere 90 y 4 (3 de paquetes + 1 suelto).');

    $page->call('edit', $row->subject_id);

    expect(textOf(domOf($page)->querySelector('[data-testid="panel-forecast-C1"] p')))
        ->toStartWith('Nexus sugiere 90 esperados y 4 grupos (3 de paquetes + 1 suelto).');
});
