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
