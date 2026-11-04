<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;

/*
| Propuesta de carrera en el navegador (tarea 2.7): el coordinador entra desde el menú, cambia los grupos de una
| materia en la tabla y envía la propuesta; después la ve «Enviada». En la retícula marca las materias sin grupos
| (maqueta C) y quita el resaltado.
*/

beforeEach(function () {
    $campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $term = Term::factory()->current()->create(['code' => '20271']);
    TermOpening::factory()->create(['term_id' => $term->id, 'campus_id' => $campus->id]);
    $career = Career::factory()->create(['code' => 'ISC', 'name' => 'Sistemas Computacionales']);
    CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => $campus->id]);
    $department = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $proposal = CareerProposal::factory()->create(['term_id' => $term->id, 'campus_id' => $campus->id, 'career_id' => $career->id]);
    ProposalItem::factory()->create([
        'career_proposal_id' => $proposal->id,
        'subject_id' => Subject::factory()->create(['code' => 'ACF-0901', 'name' => 'Cálculo Diferencial', 'department_id' => $department->id])->id,
        'requested_groups' => 2,
    ]);
    $this->proposal = $proposal;
    $this->department = $department;

    User::factory()->withRole(Role::Coordinador, ['campus_id' => $campus->id, 'career_id' => $career->id])->create(['username' => '19110003']);
});

it('el coordinador cambia los grupos de una materia en la tabla y envía la propuesta', function () {
    visit('/login')
        ->fill('username', '19110003')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/academic-load/proposal')
        ->assertSee('Propuesta de carrera')
        ->assertSee('Borrador')
        ->assertSee('Cálculo Diferencial')
        ->click('@view-table')
        ->assertPresent('@row-ACF-0901')
        ->script("(() => { const input = document.querySelector('[data-testid=row-ACF-0901] [data-field=requested_groups]'); input.value = '3'; input.dispatchEvent(new Event('change', { bubbles: true })); })()");

    $page = visit('/academic-load/proposal?vista=tabla')
        ->assertValue('[data-testid=row-ACF-0901] [data-field=requested_groups]', '3')
        ->click('@submit-proposal')
        ->click('@confirm-submit')
        ->assertSee('Enviada')
        ->assertNoJavaScriptErrors();

    expect($this->proposal->fresh()->status)->toBe(ProposalStatus::Submitted);
});

it('el coordinador marca en la retícula las materias sin grupos y quita el resaltado', function () {
    ProposalItem::factory()->create([
        'career_proposal_id' => $this->proposal->id,
        'subject_id' => Subject::factory()->create(['code' => 'ACF-0902', 'name' => 'Cálculo Integral', 'department_id' => $this->department->id])->id,
        'semester' => 2,
        'requested_groups' => 0,
    ]);

    visit('/login')
        ->fill('username', '19110003')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/academic-load/proposal')
        ->assertPresent('@proposal-grid')
        ->assertMissing('[data-testid=selection-bar][data-shown]')
        ->click('@filter-sin')
        ->assertPresent('[data-testid=proposal-grid][data-focus]')
        ->assertPresent('[data-testid=tile-ACF-0902][data-on-chain]')
        ->assertMissing('[data-testid=tile-ACF-0901][data-on-chain]')
        ->assertSeeIn('@chain-info', 'Sin grupos: 1 materia.')
        ->assertQueryStringHas('grupos', 'sin')
        ->click('@clear-highlight')
        ->assertMissing('[data-testid=proposal-grid][data-focus]')
        ->assertMissing('[data-testid=selection-bar][data-shown]')
        ->assertQueryStringMissing('grupos')
        ->assertNoJavaScriptErrors();
});
