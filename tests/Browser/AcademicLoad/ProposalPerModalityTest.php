<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Notifications\ProposalSubmittedByPeer;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/*
| La propuesta por carrera y modalidad en el navegador (M6, M7; maqueta U1, opción A «Columnas por campus»): la
| coordinadora de C2 abre la propuesta presencial de Sistemas, que tiene las columnas de C1 y C2; cambia los grupos de
| su columna, ve la de C1 de solo lectura con quién la captura y la envía. Nexus avisa al coordinador de C1 (MS18).
*/

beforeEach(function () {
    $presencial = Modality::query()->where('code', 'PRES')->sole();
    $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $term = Term::factory()->current()->create(['code' => '20271']);
    TermOpening::factory()->create(['term_id' => $term->id, 'modality_id' => $presencial->id]);
    $career = Career::factory()->create(['code' => 'ISIC', 'name' => 'Sistemas Computacionales']);
    CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => $c1->id]);
    CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => $c2->id]);
    $department = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $calculo = Subject::factory()->create(['code' => 'ACF-0901', 'name' => 'Cálculo Diferencial', 'department_id' => $department->id]);
    $this->proposal = CareerProposal::factory()->create(['term_id' => $term->id, 'modality_id' => $presencial->id, 'career_id' => $career->id]);
    $this->inC1 = ProposalItem::factory()->create(['career_proposal_id' => $this->proposal->id, 'campus_id' => $c1->id, 'subject_id' => $calculo->id, 'eligible_students' => 174, 'expected_students' => 151, 'requested_groups' => 5]);
    $this->inC2 = ProposalItem::factory()->create(['career_proposal_id' => $this->proposal->id, 'campus_id' => $c2->id, 'subject_id' => $calculo->id, 'eligible_students' => 36, 'expected_students' => 31, 'requested_groups' => 1]);

    $this->ofC1 = User::factory()->withRole(Role::Coordinador, ['campus_id' => $c1->id, 'career_id' => $career->id])->create(['name' => 'Héctor Villalobos Ríos']);
    User::factory()->withRole(Role::Coordinador, ['campus_id' => $c2->id, 'career_id' => $career->id])->create(['username' => '19110005', 'name' => 'Daniela Quintero Salas']);
});

it('M6, M7: la coordinadora de C2 cambia su columna, ve la de C1 bloqueada y envía la propuesta', function () {
    visit('/login')
        ->fill('username', '19110005')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/academic-load/proposal?vista=tabla')
        ->assertSee('Se ofrece en C1 y C2')
        ->assertSeeIn('@proposal-scope', 'C1 lo captura Héctor Villalobos Ríos.')
        ->assertPresent('[data-readonly-column=C1]')
        ->assertMissing('[data-readonly-column=C2]')
        ->assertMissing('#cell-'.$this->inC1->id.'-requested_groups')
        ->assertPresent('#cell-'.$this->inC2->id.'-requested_groups')
        ->script("(() => { const input = document.querySelector('#cell-{$this->inC2->id}-requested_groups'); input.value = '2'; input.dispatchEvent(new Event('change', { bubbles: true })); })()");

    visit('/academic-load/proposal?vista=tabla')
        ->assertValue('#cell-'.$this->inC2->id.'-requested_groups', '2')
        ->assertSeeIn('[data-testid=row-ACF-0901] [data-row-total]', '7')
        ->click('@submit-proposal')
        ->assertSeeIn('@submit-notice', 'Héctor Villalobos Ríos, que coordina contigo')
        ->click('@confirm-submit')
        ->assertSee('Enviada')
        ->assertNoJavaScriptErrors();

    expect($this->inC2->fresh()->requested_groups)->toBe(2)
        ->and($this->inC1->fresh()->requested_groups)->toBe(5)
        ->and($this->proposal->fresh()->status)->toBe(ProposalStatus::Submitted)
        ->and(DatabaseNotification::query()->where('type', ProposalSubmittedByPeer::class)->where('notifiable_id', $this->ofC1->id)->exists())->toBeTrue();
});

/**
 * Más materias para que las capturas se parezcan a una propuesta de verdad: tres departamentos, tres semestres, un
 * departamento que regresó su parte y otro que la aceptó en `$returned`.
 */
function proposalPerModalityVolume(object $test): void
{
    $departments = [
        'SC' => Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']),
        'CEA' => Department::factory()->create(['code' => 'CEA', 'name' => 'Ciencias Económico Administrativas']),
    ];
    $rows = [
        ['SCD-1008', 'Fundamentos de Programación', 'SC', 1, [195, 172, 6], [37, 34, 2]],
        ['AEF-1041', 'Matemáticas Discretas', 'SC', 1, [188, 163, 5], [39, 35, 1]],
        ['ACA-0907', 'Taller de Ética', 'CEA', 1, [184, 171, 5], [39, 52, 2]],
        ['ACF-0902', 'Cálculo Integral', 'CB', 2, [166, 140, 4], [36, 30, 1]],
        ['AED-1286', 'Programación Orientada a Objetos', 'SC', 2, [158, 150, 5], [36, 33, 2]],
        ['SCD-1027', 'Tópicos Avanzados de Programación', 'SC', 4, [129, 110, 4], [29, 0, 0]],
    ];
    $c1 = Campus::query()->where('code', 'C1')->sole();
    $c2 = Campus::query()->where('code', 'C2')->sole();

    foreach ($rows as [$code, $name, $department, $semester, $inC1, $inC2]) {
        $subject = Subject::factory()->create(['code' => $code, 'name' => $name, 'department_id' => ($departments[$department] ?? $test->inC1->subject->department)->id]);

        foreach ([[$c1, $inC1], [$c2, $inC2]] as [$campus, [$eligible, $expected, $groups]]) {
            ProposalItem::factory()->create(['career_proposal_id' => $test->proposal->id, 'campus_id' => $campus->id, 'subject_id' => $subject->id, 'semester' => $semester, 'eligible_students' => $eligible, 'expected_students' => $expected, 'requested_groups' => $groups]);
        }
    }

    $test->proposal->update(['eligible_students' => 786]);
}

function signInForProposal(string $username)
{
    return visit('/login')->fill('username', $username)->fill('password', 'password')->press('Entrar')->assertPathIs('/');
}

it('capturas de la propuesta por modalidad: C2 con C1 de solo lectura, sin campus, una columna y enviada', function () {
    proposalPerModalityVolume($this);
    $shots = fn ($page, string $name, array $widths) => array_map(fn (int $width) => $page->resize($width, $width < 800 ? 900 : 1000)->screenshot(filename: "{$name}-{$width}"), $widths);

    // (b) La coordinadora de C2: la columna de C1 de solo lectura.
    $page = signInForProposal('19110005')->navigate('/academic-load/proposal?vista=tabla')->assertSee('Cálculo Diferencial');
    $shots($page, 'propuesta-tabla-c2', [390, 768, 1280, 1920, 2560]);
    $page->navigate('/academic-load/proposal?vista=reticula')->assertPresent('@proposal-grid');
    $shots($page, 'propuesta-reticula-c2', [390, 1280, 1920]);
    $page->navigate('/academic-load/proposal?vista=tabla')->resize(1280, 1000)->click('@open-ACF-0901')->assertSee('C1 lo captura')->screenshot(filename: 'propuesta-panel-c2-1280');
    $page->resize(390, 900)->screenshot(filename: 'propuesta-panel-c2-390');
    $page->navigate('/academic-load/proposal?vista=tabla')->resize(1280, 1000)
        ->script("document.documentElement.dataset.theme = 'nexus-dark'");
    $page->screenshot(filename: 'propuesta-tabla-c2-oscuro-1280');
    $page->script("document.documentElement.dataset.theme = 'nexus'");
    $page->click('@submit-proposal')->assertSee('que coordina contigo')->screenshot(filename: 'propuesta-enviar-c2-1280');
    $page->assertNoJavaScriptErrors();
});

it('capturas: el coordinador sin campus edita las dos columnas, y una carrera solo de C1 tiene una', function () {
    proposalPerModalityVolume($this);
    $career = $this->proposal->career;
    $both = User::factory()->withRole(Role::Coordinador, ['career_id' => $career->id])->create(['username' => '19110006', 'name' => 'Rocío Barraza Lugo']);
    $itic = Career::factory()->create(['code' => 'ITIC', 'name' => 'Tecnologías de la Información y Comunicaciones']);
    $c1 = Campus::query()->where('code', 'C1')->sole();
    CareerCampus::factory()->create(['career_id' => $itic->id, 'campus_id' => $c1->id]);
    $single = CareerProposal::factory()->create(['term_id' => $this->proposal->term_id, 'modality_id' => $this->proposal->modality_id, 'career_id' => $itic->id, 'eligible_students' => 268]);
    foreach ([['ACF-0901', 86, 75, 3], ['TIF-1019', 96, 85, 3], ['TIC-1022', 93, 81, 3]] as [$code, $eligible, $expected, $groups]) {
        $subject = Subject::query()->where('code', $code)->first() ?? Subject::factory()->create(['code' => $code, 'name' => "Materia {$code}", 'department_id' => $this->inC1->subject->department_id]);
        ProposalItem::factory()->create(['career_proposal_id' => $single->id, 'campus_id' => $c1->id, 'subject_id' => $subject->id, 'eligible_students' => $eligible, 'expected_students' => $expected, 'requested_groups' => $groups]);
    }
    $both->roleAssignments()->create(['role' => Role::Coordinador, 'modality_id' => $this->proposal->modality_id, 'career_id' => $itic->id, 'valid_from' => today()->subMonth()->toDateString()]);

    $page = signInForProposal('19110006')->navigate('/academic-load/proposal?vista=tabla&carrera='.$career->id)->assertSee('Cálculo Diferencial')->assertMissing('[data-readonly-column]');
    $page->resize(1280, 1000)->screenshot(filename: 'propuesta-tabla-sin-campus-1280');
    $page->resize(390, 900)->screenshot(filename: 'propuesta-tabla-sin-campus-390');
    $page->navigate('/academic-load/proposal?vista=tabla&carrera='.$itic->id)->assertSee('Se ofrece solo en C1')->assertMissing('[data-column-total]');
    $page->resize(1280, 1000)->screenshot(filename: 'propuesta-tabla-una-columna-1280');
    $page->resize(390, 900)->screenshot(filename: 'propuesta-tabla-una-columna-390');
    $page->assertNoJavaScriptErrors();
});

it('capturas: la propuesta regresada dice quién la envió y cuándo, con el estado de la parte de cada departamento', function () {
    proposalPerModalityVolume($this);
    $this->proposal->update(['status' => ProposalStatus::Returned, 'submitted_by' => $this->ofC1->id, 'submitted_at' => now()->subDay()->setTime(10, 32)]);
    $parts = ['CB' => 'returned', 'SC' => 'pending', 'CEA' => 'accepted'];
    foreach ($parts as $code => $state) {
        $department = Department::query()->where('code', $code)->sole();
        $factory = match ($state) {
            'returned' => ProposalReview::factory()->returned('En C2, Cálculo Integral cabe en un grupo: con 30 esperados no se justifican dos.'),
            'accepted' => ProposalReview::factory()->accepted(),
            default => ProposalReview::factory(),
        };
        $factory->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $department->id, 'decided_at' => $state === 'pending' ? null : now()]);
    }

    $page = signInForProposal('19110005')->navigate('/academic-load/proposal?vista=tabla')
        ->assertSeeIn('@proposal-sent', 'Enviada por Héctor Villalobos Ríos (C1)')
        ->assertSeeIn('@proposal-sent', 'Regresada por CB');
    $page->resize(1280, 1000)->screenshot(filename: 'propuesta-regresada-c2-1280');
    $page->resize(390, 900)->screenshot(filename: 'propuesta-regresada-c2-390');
    $page->resize(1280, 1000)->script("document.documentElement.dataset.theme = 'nexus-dark'");
    $page->screenshot(filename: 'propuesta-regresada-oscuro-1280');
    $page->navigate('/academic-load/proposal?vista=reticula')->script("document.documentElement.dataset.theme = 'nexus-dark'");
    $page->assertPresent('@proposal-grid')->screenshot(filename: 'propuesta-reticula-oscuro-1280');
    $page->assertNoJavaScriptErrors();
});
