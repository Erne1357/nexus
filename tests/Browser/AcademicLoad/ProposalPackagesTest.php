<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalIntake;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalPackage;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;

/*
| Los paquetes de primer semestre en el navegador (tarea 5.6b): el coordinador de C1 arma un paquete con las materias de 1.º
| desde el aviso de la propuesta recién abierta, abre 2 en su campus y ve los grupos de la fila sumados (el suelto más los dos
| del paquete), con cuántos salen de paquetes.
*/

beforeEach(function () {
    $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    TermOpening::factory()->create(['term_id' => $term->id, 'modality_id' => modalityOf('PRES')->id]);
    $career = Career::factory()->create(['code' => 'ISC', 'name' => 'Sistemas Computacionales']);
    CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => $c1->id]);
    CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => $c2->id]);
    $department = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $this->proposal = CareerProposal::factory()->create(['term_id' => $term->id, 'career_id' => $career->id]);

    foreach ([['ACF-0901', 'Cálculo Diferencial', 1], ['ACA-0907', 'Taller de Ética', 1], ['ACF-0902', 'Cálculo Integral', 2]] as [$code, $name, $semester]) {
        $subject = Subject::factory()->create(['code' => $code, 'name' => $name, 'department_id' => $department->id]);

        foreach ([$c1, $c2] as $campus) {
            ProposalItem::factory()->create([
                'career_proposal_id' => $this->proposal->id,
                'campus_id' => $campus->id,
                'subject_id' => $subject->id,
                'semester' => $semester,
                'requested_groups' => 1,
                'captured_at' => now(),
            ]);
        }
    }

    ProposalIntake::factory()->create(['career_proposal_id' => $this->proposal->id, 'campus_id' => $c1->id, 'suggested_new_students' => 30, 'new_students' => 30]);
    ProposalIntake::factory()->create(['career_proposal_id' => $this->proposal->id, 'campus_id' => $c2->id, 'suggested_new_students' => 30, 'new_students' => 30]);

    $this->c1 = $c1;
    User::factory()->withRole(Role::Coordinador, ['campus_id' => $c1->id, 'career_id' => $career->id])->create(['username' => '19110005']);
});

it('el coordinador arma un paquete con las materias de 1.º, abre 2 en su campus y ve los grupos de la fila sumados', function () {
    $page = visit('/login')
        ->fill('username', '19110005')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/academic-load/proposal?vista=tabla')
        ->assertSeeIn('@packages-empty', 'Todavía no hay paquetes.')
        ->click('@packages-first')
        ->assertSee('Nuevo paquete')
        ->assertChecked('@package-subject-ACF-0901')
        ->assertChecked('@package-subject-ACA-0907')
        ->assertNotChecked('@package-subject-ACF-0902')
        ->click('@package-save')
        ->assertPresent('@package-P1')
        ->assertSeeIn('@package-P1', '2 materias')
        ->assertValue('@package-count-P1-C1', '1');

    $page->script("(() => { const input = document.querySelector('[data-testid=package-count-P1-C1]'); input.value = '2'; input.dispatchEvent(new Event('change', { bubbles: true })); })()");

    $page->assertSeeIn('@packages-open-C1', '2')
        ->assertSeeIn('[data-testid=row-ACF-0901] [data-package-groups]', '2 de paq.')
        ->assertValue('[data-testid=row-ACF-0901] input[data-field=requested_groups]', '3')
        ->assertSeeIn('@packages-status-C1', 'Alcanza: 70 lugares')
        ->assertNoJavaScriptErrors();

    $package = ProposalPackage::query()->where('career_proposal_id', $this->proposal->id)->sole();
    $row = ProposalItem::query()->where('career_proposal_id', $this->proposal->id)->where('campus_id', $this->c1->id)->whereHas('subject', fn ($query) => $query->where('code', 'ACF-0901'))->sole();

    expect($package->campuses()->where('campus_id', $this->c1->id)->sole()->count)->toBe(2)
        ->and($row->only(['package_groups', 'requested_groups']))->toBe(['package_groups' => 2, 'requested_groups' => 3]);
});
