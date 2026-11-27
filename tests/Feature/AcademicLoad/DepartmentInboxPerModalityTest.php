<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Queries\DepartmentInboxQuery;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use Livewire\Livewire;

/*
| La bandeja del departamento es por modalidad (M6): muestra las propuestas de la modalidad, una por carrera, con las
| cantidades de cada campus en la parte del departamento. Los paralelos se sugieren por materia y campus, porque un
| grupo es de un campus (M8) y no junta alumnos de C1 y C2 (supuesto por confirmar).
*/

beforeEach(function () {
    $this->term = Term::factory()->create();
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->cb = Department::factory()->create(['code' => 'CB']);
    $this->calculo = Subject::factory()->create(['code' => 'ACF-0901', 'department_id' => $this->cb->id, 'needs_lab' => false]);
});

function inboxCareerIn(string $code, Campus ...$campuses): Career
{
    $career = Career::factory()->create(['code' => $code]);

    foreach ($campuses as $campus) {
        CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => $campus->id]);
    }

    return $career;
}

function inboxPerModalityProposal(Career $career, string $modality = 'PRES'): CareerProposal
{
    $proposal = CareerProposal::factory()->submitted()->create(['term_id' => test()->term->id, 'modality_id' => modalityOf($modality)->id, 'career_id' => $career->id]);
    ProposalReview::factory()->create(['career_proposal_id' => $proposal->id, 'department_id' => test()->cb->id]);

    return $proposal;
}

function inboxPerModalityRow(CareerProposal $proposal, Campus $campus, int $expected, int $groups): ProposalItem
{
    return ProposalItem::factory()->create([
        'career_proposal_id' => $proposal->id,
        'campus_id' => $campus->id,
        'subject_id' => test()->calculo->id,
        'expected_students' => $expected,
        'requested_groups' => $groups,
    ]);
}

it('M6: la bandeja de la modalidad lleva una propuesta por carrera con las filas de cada campus', function () {
    $isc = inboxCareerIn('ISC', $this->c1, $this->c2);
    $ige = inboxCareerIn('IGE', $this->c1);
    $proposal = inboxPerModalityProposal($isc);
    inboxPerModalityRow($proposal, $this->c1, 60, 2);
    inboxPerModalityRow($proposal, $this->c2, 30, 1);
    $ead = Career::factory()->create(['code' => 'IEAD']);
    inboxPerModalityRow(inboxPerModalityProposal($ead, 'EAD'), $this->c1, 10, 1);

    $inbox = app(DepartmentInboxQuery::class)->inbox($this->term, modalityOf('PRES'), $this->cb);
    $row = $inbox->career($isc->id);

    expect(array_map(fn (array $career): string => $career['career']->code, $inbox->careers))->toBe(['ISC', 'IGE'])
        ->and($row['groups'])->toBe(3)
        ->and($row['subjects'])->toBe(1)
        ->and(array_map(fn (array $entry): array => [$entry['item']->campus->code, $entry['item']->requested_groups], $inbox->rowsFor($isc->id)))->toBe([['C1', 2], ['C2', 1]])
        ->and($inbox->modality->code)->toBe('PRES');
});

it('sugiere los paralelos de cada materia por campus: no junta alumnos de C1 y C2 (supuesto por confirmar)', function () {
    $isc = inboxCareerIn('ISC', $this->c1, $this->c2);
    $iind = inboxCareerIn('IIND', $this->c2);
    $sistemas = inboxPerModalityProposal($isc);
    inboxPerModalityRow($sistemas, $this->c1, 60, 2);
    inboxPerModalityRow($sistemas, $this->c2, 30, 1);
    inboxPerModalityRow(inboxPerModalityProposal($iind), $this->c2, 10, 1);

    $inbox = app(DepartmentInboxQuery::class)->inbox($this->term, modalityOf('PRES'), $this->cb);

    expect(array_map(fn (array $row): array => [$row['campus']?->code, array_keys($row['parallels']->careers), $row['parallels']->requested, $row['parallels']->suggested], $inbox->subjects))->toBe([
        ['C1', ['ISC'], 2, 2],
        ['C2', ['IIND', 'ISC'], 2, 2],
    ])
        ->and($inbox->totalFor($this->calculo->id, $this->c2->id))->toBe(['careers' => 2, 'groups' => 2, 'expected' => 40]);
});

it('M6: el detalle de la bandeja pinta cada fila con su campus y el total de grupos por campus', function () {
    $term = Term::factory()->current()->create();
    TermOpening::factory()->create(['term_id' => $term->id, 'modality_id' => modalityOf('PRES')->id]);
    $this->term = $term;
    $isc = inboxCareerIn('ISC', $this->c1, $this->c2);
    $proposal = inboxPerModalityProposal($isc);
    inboxPerModalityRow($proposal, $this->c1, 60, 2);
    inboxPerModalityRow($proposal, $this->c2, 30, 1);
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->cb->id])->create();

    $html = Livewire::actingAs($jefe)->withQueryParams(['carrera' => $isc->id])->test('academic-load::pages.inbox')->html();

    expect($html)->toContain('data-campus="C1"')->toContain('data-campus="C2"')
        ->and(textOf(domOf($html)->querySelector('[data-testid="inbox-total-by-campus"]')))->toBe('C1 2 · C2 1');
});

describe('alcance de la bandeja (maqueta U2, opción A)', function () {
    beforeEach(function () {
        $this->term = Term::factory()->current()->create();
        TermOpening::factory()->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('PRES')->id]);
        $this->isc = inboxCareerIn('ISC', $this->c1, $this->c2);
        $proposal = inboxPerModalityProposal($this->isc);
        inboxPerModalityRow($proposal, $this->c1, 60, 2);
        inboxPerModalityRow($proposal, $this->c2, 30, 1);
        $this->ige = inboxCareerIn('IGE', $this->c1);
        inboxPerModalityRow(inboxPerModalityProposal($this->ige), $this->c1, 20, 1);
        $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->cb->id])->create();
    });

    it('el filtro de campus cuenta las carreras que piden algo en cada campus, y la lista dice los grupos de cada uno', function () {
        $dom = domOf(Livewire::actingAs($this->jefe)->test('academic-load::pages.inbox')->html());

        expect(textOf($dom->querySelector('[data-testid="campus-count-todos"]')))->toBe('2')
            ->and(textOf($dom->querySelector('[data-testid="campus-count-C1"]')))->toBe('2')
            ->and(textOf($dom->querySelector('[data-testid="campus-count-C2"]')))->toBe('1')
            ->and(textOf($dom->querySelector('[data-career="ISC"] [data-testid="career-by-campus"]')))->toContain('2', '1', 'grupos · 1 materia')
            ->and(textOf($dom->querySelector('[data-testid="inbox-campus"]')))->toContain('La parte de CB se acepta o se regresa una vez, para todos sus campus.');
    });

    it('con ?campus=C2 el detalle solo tiene las filas de C2 y las cifras de ese campus, y la parte se sigue decidiendo una vez', function () {
        $page = Livewire::actingAs($this->jefe)->withQueryParams(['carrera' => $this->isc->id, 'campus' => 'C2'])->test('academic-load::pages.inbox');
        $html = $page->html();

        expect($html)->toContain('data-campus="C2"')->not->toContain('data-campus="C1"')
            ->and(textOf(domOf($html)->querySelector('[data-testid="inbox-detail-total"]')))->toContain('1 grupo en C2')
            ->and(textOf(domOf($html)->querySelector('[data-career="IGE"]')))->toContain('Nada en C2')
            ->and(domOf($html)->querySelector('[data-testid="accept-part"]'))->not->toBeNull();
    });

    it('MS6: un jefe de proyecto docente con nombramiento en C2 ve las filas de C1 de toda la modalidad', function () {
        $jpd = User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c2->id, 'department_id' => $this->cb->id])->create();

        $html = Livewire::actingAs($jpd)->withQueryParams(['carrera' => $this->isc->id, 'campus' => 'C1'])->test('academic-load::pages.inbox')->html();

        expect($html)->toContain('data-campus="C1"');
    });

    it('fija la modalidad que resolvió: si después se abre otra antes por clave, la bandeja no cambia de modalidad', function () {
        modalityOf('EAD')->update(['is_active' => true]);
        $page = Livewire::actingAs($this->jefe)->test('academic-load::pages.inbox')->assertSet('modalidad', 'PRES');

        TermOpening::factory()->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('EAD')->id]);

        $page->set('filter', 'pendientes')->assertSet('modalidad', 'PRES');
        expect(domOf($page->html())->querySelector('[data-testid="modality-scope"] option[value="PRES"]')?->hasAttribute('selected'))->toBeTrue();
    });

    it('otra modalidad: el jefe de proyecto docente de EAD no ve la bandeja presencial aunque pida ?modalidad=PRES', function () {
        modalityOf('EAD')->update(['is_active' => true]);
        $jpd = User::factory()->withRole(Role::JefeProyectoDocente, ['modality_id' => modalityOf('EAD')->id, 'department_id' => $this->cb->id])->create();

        Livewire::actingAs($jpd)->withQueryParams(['modalidad' => 'PRES'])->test('academic-load::pages.inbox')
            ->assertSee('El periodo '.$this->term->code.' no está abierto en EAD.')
            ->assertDontSeeHtml('data-campus="C1"');
    });
});
