<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Proposals\UpdateProposalItem;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| M14 (confirmado el 12 de noviembre de 2026): un coordinador de la carrera deja en cero los grupos pedidos de la fila de
| un campus inactivo, y nada más, para poder enviar la propuesta (HC-13 por columna). El nombramiento no cubre un campus
| inactivo (MS8), así que corregir la fila (CareerProposalPolicy::update) sigue negado; la regla es otra ability,
| `zeroInactiveCampus`, y la Action solo escribe el cero.
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
    $cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $this->proposal = CareerProposal::factory()->create(['term_id' => $this->term->id, 'modality_id' => $this->presencial->id, 'career_id' => $this->career->id]);
    $this->calculus = Subject::factory()->create(['code' => 'ACF-0901', 'name' => 'Cálculo Diferencial', 'department_id' => $cb->id]);
    $this->inC1 = ProposalItem::factory()->create(['career_proposal_id' => $this->proposal->id, 'campus_id' => $this->c1->id, 'subject_id' => $this->calculus->id, 'semester' => 1, 'eligible_students' => 174, 'expected_students' => 151, 'requested_groups' => 5, 'notes' => 'Turno matutino']);
    $this->inC2 = ProposalItem::factory()->create(['career_proposal_id' => $this->proposal->id, 'campus_id' => $this->c2->id, 'subject_id' => $this->calculus->id, 'semester' => 1, 'eligible_students' => 36, 'expected_students' => 31, 'requested_groups' => 1]);
    $this->ofC2 = User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->presencial->id, 'campus_id' => $this->c2->id, 'career_id' => $this->career->id])->create();
    $this->ofBoth = User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->presencial->id, 'career_id' => $this->career->id])->create();

    // C1 deja de estar activo con la propuesta en borrador y 5 grupos pedidos ahí.
    $this->c1->update(['is_active' => false]);
});

it('M14, MS31: el coordinador de la carrera, aunque su nombramiento sea de otro campus, deja en cero los grupos de la fila del campus inactivo y después envía (HC-13 por columna)', function () {
    $page = Livewire::withQueryParams(['vista' => 'tabla'])->actingAs($this->ofC2)->test('academic-load::pages.proposal');

    $page->call('submit');
    expect($this->proposal->fresh()->status)->toBe(ProposalStatus::Draft);

    $page->assertSeeHtml('data-testid="zero-row-'.$this->inC1->id.'"')
        ->call('zeroRow', $this->inC1->id)
        ->assertHasNoErrors()
        ->assertDispatched('cell-saved', id: $this->inC1->id, field: 'requested_groups')
        ->assertDontSeeHtml('data-testid="zero-row-'.$this->inC1->id.'"');

    expect($this->inC1->fresh())
        ->requested_groups->toBe(0)
        ->expected_students->toBe(151)
        ->notes->toBe('Turno matutino')
        ->and(Activity::query()->where('subject_id', $this->inC1->id)->latest('id')->first()->attribute_changes['attributes'])->toBe(['requested_groups' => 0]);

    $page->call('submit')->assertHasNoErrors();

    expect($this->proposal->fresh()->status)->toBe(ProposalStatus::Submitted);
});

it('M14: un coordinador sin campus también, y la columna inactiva dice que solo se deja en cero', function () {
    Livewire::withQueryParams(['vista' => 'tabla'])->actingAs($this->ofBoth)->test('academic-load::pages.proposal')
        ->assertSee('C1 está inactivo: solo se pueden dejar sus grupos en 0.')
        ->call('zeroRow', $this->inC1->id)
        ->assertHasNoErrors();

    expect($this->inC1->fresh()->requested_groups)->toBe(0);
});

it('M14: cualquier otro cambio en la fila del campus inactivo sigue negado', function (string $field, string $value) {
    Livewire::withQueryParams(['vista' => 'tabla'])->actingAs($this->ofC2)->test('academic-load::pages.proposal')
        ->call('saveCell', $this->inC1->id, $field, $value)
        ->assertForbidden();

    expect($this->inC1->fresh())->requested_groups->toBe(5)->expected_students->toBe(151);
})->with([
    'grupos distintos de cero' => ['requested_groups', '2'],
    'grupos en cero por la celda de siempre' => ['requested_groups', '0'],
    'alumnos esperados' => ['expected_students', '10'],
]);

it('M14: no aplica a la fila de un campus activo, ni la tiene quien no coordina la carrera', function () {
    $otherCareer = Career::factory()->create(['code' => 'IIA']);
    $ofOther = User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->presencial->id, 'career_id' => $otherCareer->id])->create();
    $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

    expect($this->ofC2->can('zeroInactiveCampus', [$this->proposal, $this->inC1]))->toBeTrue()
        ->and($this->ofC2->can('zeroInactiveCampus', [$this->proposal, $this->inC2]))->toBeFalse()
        ->and($ofOther->can('zeroInactiveCampus', [$this->proposal, $this->inC1]))->toBeFalse()
        ->and($subdireccion->can('zeroInactiveCampus', [$this->proposal, $this->inC1]))->toBeFalse()
        ->and($this->ofC2->can('update', [$this->proposal, $this->inC1]))->toBeFalse();

    Livewire::withQueryParams(['vista' => 'tabla'])->actingAs($this->ofC2)->test('academic-load::pages.proposal')
        ->call('zeroRow', $this->inC2->id)
        ->assertForbidden();

    expect($this->inC2->fresh()->requested_groups)->toBe(1);
});

it('M14: la Action solo deja en cero una fila cuyo campus sigue inactivo', function () {
    $this->c1->update(['is_active' => true]);

    expect(fn () => app(UpdateProposalItem::class)->zeroRequestedGroups($this->inC1, $this->ofC2))
        ->toThrow(ValidationException::class, 'C1 está activo: corrige la fila como siempre.');

    expect($this->inC1->fresh()->requested_groups)->toBe(5);
});

it('M14: el panel tampoco guarda ni sugiere en la fila del campus inactivo: una fila armada a mano es 403', function () {
    $page = fn () => Livewire::withQueryParams(['vista' => 'tabla'])->actingAs($this->ofBoth)->test('academic-load::pages.proposal');

    $page()->set('form', [$this->inC1->id => ['expected_students' => 151, 'requested_groups' => 0, 'notes' => '']])
        ->call('save')
        ->assertForbidden();

    $page()->call('useSuggested', $this->inC1->id)->assertForbidden();

    expect($this->inC1->fresh())->requested_groups->toBe(5)->expected_students->toBe(151);
});

it('M14: el aviso de la columna inactiva sale solo mientras la propuesta se edita y alguna fila de ella pide grupos', function () {
    $alert = 'data-testid="inactive-column-C1"';
    $page = fn () => Livewire::withQueryParams(['vista' => 'tabla'])->actingAs($this->ofBoth)->test('academic-load::pages.proposal');

    $page()->assertSeeHtml($alert);

    $this->inC1->update(['requested_groups' => 0]);
    $page()->assertDontSeeHtml($alert);

    $this->inC1->update(['requested_groups' => 2]);
    $this->proposal->update(['status' => ProposalStatus::Submitted]);
    $page()->assertDontSeeHtml($alert);
});
