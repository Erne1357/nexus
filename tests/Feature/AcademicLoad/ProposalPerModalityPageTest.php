<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Queries\ProposalOverview;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| La propuesta de carrera es una por carrera y modalidad, con una columna por campus (M6; maqueta U1, opción A
| «Columnas por campus»): el coordinador de un campus ve las dos columnas y los alumnos posibles de cada campus, pero
| solo cambia los grupos pedidos y los alumnos esperados del suyo (M7); el de la otra columna se ve de solo lectura, con
| quién la captura. Un coordinador sin campus cambia las dos. Una carrera que solo se ofrece en un campus tiene una
| columna (MS5).
*/

beforeEach(function () {
    $this->presencial = Modality::query()->where('code', 'PRES')->sole();
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    TermOpening::factory()->create(['term_id' => $this->term->id, 'modality_id' => $this->presencial->id]);
    $this->career = Career::factory()->create(['code' => 'ISC', 'name' => 'Sistemas']);
    CareerCampus::factory()->create(['career_id' => $this->career->id, 'campus_id' => $this->c1->id]);
    CareerCampus::factory()->create(['career_id' => $this->career->id, 'campus_id' => $this->c2->id]);
    $this->cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $this->proposal = CareerProposal::factory()->create(['term_id' => $this->term->id, 'modality_id' => $this->presencial->id, 'career_id' => $this->career->id]);
    $this->calculus = Subject::factory()->create(['code' => 'ACF-0901', 'name' => 'Cálculo Diferencial', 'department_id' => $this->cb->id]);
    $this->inC1 = perModalityRow($this->c1, 174, 151, 5);
    $this->inC2 = perModalityRow($this->c2, 36, 31, 1);

    $this->ofC1 = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c1->id, 'career_id' => $this->career->id])->create(['name' => 'Héctor Villalobos']);
    $this->ofC2 = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->career->id])->create(['name' => 'Daniela Quintero']);
});

function perModalityRow(Campus $campus, int $eligible, int $expected, int $groups): ProposalItem
{
    return ProposalItem::factory()->create([
        'career_proposal_id' => test()->proposal->id,
        'campus_id' => $campus->id,
        'subject_id' => test()->calculus->id,
        'semester' => 1,
        'eligible_students' => $eligible,
        'expected_students' => $expected,
        'requested_groups' => $groups,
    ]);
}

/**
 * @param  array<string, string>  $query
 */
function perModalityPageAs(User $user, array $query = []): Testable
{
    return Livewire::withQueryParams($query)->actingAs($user)->test('academic-load::pages.proposal');
}

it('M6: una sola propuesta de la carrera en la modalidad, con las columnas de C1 y C2 y los totales por campus', function () {
    perModalityPageAs($this->ofC2, ['vista' => 'tabla'])
        ->assertOk()
        ->assertViewHas('overview', fn (ProposalOverview $overview): bool => $overview->proposal->is($this->proposal)
            && array_map(fn (?Campus $campus): ?string => $campus?->code, $overview->columns) === ['C1', 'C2']
            && $overview->totals['groups'] === 6
            && $overview->totals['groups_by_campus'] === [$this->c1->id => 5, $this->c2->id => 1])
        ->assertSeeHtml('data-column="C1"')
        ->assertSeeHtml('data-column="C2"');
});

it('M7: el coordinador de C2 cambia su fila y no la de C1', function () {
    perModalityPageAs($this->ofC2, ['vista' => 'tabla'])
        ->call('saveCell', $this->inC2->id, 'requested_groups', '2')
        ->assertHasNoErrors();

    expect($this->inC2->fresh()->requested_groups)->toBe(2);

    perModalityPageAs($this->ofC2, ['vista' => 'tabla'])->call('saveCell', $this->inC1->id, 'requested_groups', '9')->assertForbidden();

    perModalityPageAs($this->ofC2)
        ->call('edit', $this->calculus->id)
        ->assertSet('showDrawer', true)
        ->assertSet('form', [$this->inC2->id => ['expected_students' => 31, 'requested_groups' => 2, 'notes' => '']])
        ->set("form.{$this->inC1->id}", ['expected_students' => 1, 'requested_groups' => 9, 'notes' => ''])
        ->call('save')
        ->assertForbidden();

    perModalityPageAs($this->ofC2)->call('edit', $this->calculus->id)->call('useSuggested', $this->inC1->id)->assertForbidden();

    expect($this->inC1->fresh()->only(['expected_students', 'requested_groups']))->toBe(['expected_students' => 151, 'requested_groups' => 5]);
});

it('M7: al coordinador de C2 la columna de C1 se le ve de solo lectura y dice quién la captura', function () {
    perModalityPageAs($this->ofC2, ['vista' => 'tabla'])
        ->assertSeeHtml('data-readonly-column="C1"')
        ->assertDontSeeHtml('data-readonly-column="C2"')
        ->assertSee('C1 lo captura Héctor Villalobos')
        ->assertSeeHtml('id="cell-'.$this->inC2->id.'-requested_groups"')
        ->assertDontSeeHtml('id="cell-'.$this->inC1->id.'-requested_groups"');
});

it('M5: un coordinador sin campus cambia las dos columnas', function () {
    $both = User::factory()->withRole(Role::Coordinador, ['career_id' => $this->career->id])->create();

    perModalityPageAs($both, ['vista' => 'tabla'])
        ->assertDontSeeHtml('data-readonly-column=')
        ->call('edit', $this->calculus->id)
        ->assertSet('form', [
            $this->inC1->id => ['expected_students' => 151, 'requested_groups' => 5, 'notes' => ''],
            $this->inC2->id => ['expected_students' => 31, 'requested_groups' => 1, 'notes' => ''],
        ])
        ->set("form.{$this->inC1->id}.requested_groups", 4)
        ->set("form.{$this->inC2->id}.requested_groups", 2)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showDrawer', false);

    expect($this->inC1->fresh()->requested_groups)->toBe(4)
        ->and($this->inC2->fresh()->requested_groups)->toBe(2);
});

it('MS5: una carrera solo de C1 tiene una columna, sin total entre campus', function () {
    $itic = Career::factory()->create(['code' => 'ITIC']);
    CareerCampus::factory()->create(['career_id' => $itic->id, 'campus_id' => $this->c1->id]);
    $proposal = CareerProposal::factory()->create(['term_id' => $this->term->id, 'modality_id' => $this->presencial->id, 'career_id' => $itic->id]);
    ProposalItem::factory()->create(['career_proposal_id' => $proposal->id, 'campus_id' => $this->c1->id, 'subject_id' => Subject::factory()->create(['department_id' => $this->cb->id])->id]);
    $coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c1->id, 'career_id' => $itic->id])->create();

    perModalityPageAs($coordinator, ['vista' => 'tabla'])
        ->assertViewHas('overview', fn (ProposalOverview $overview): bool => array_map(fn (?Campus $campus): ?string => $campus?->code, $overview->columns) === ['C1'])
        ->assertSeeHtml('data-column="C1"')
        ->assertDontSeeHtml('data-column="C2"')
        ->assertDontSeeHtml('data-column-total');
});

it('M6: el coordinador de C1 ve la propuesta que armó la de C2: no hay otra para su campus', function () {
    perModalityPageAs($this->ofC1)->assertViewHas('proposal', fn (CareerProposal $proposal): bool => $proposal->is($this->proposal));

    expect(CareerProposal::query()->count())->toBe(1);
});
