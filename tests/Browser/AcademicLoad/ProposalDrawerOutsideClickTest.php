<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalIntake;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;

/*
| Un clic fuera de un drawer lo cierra y no abre otro (issue #754): el velo de cada drawer es un `<label for>` de su casilla, y
| dos drawers de una misma página compartían el mismo id, así que el clic encendía la casilla del primero de la página.
*/

it('un clic fuera del drawer de una materia lo cierra y no abre el de paquetes que está debajo', function () {
    $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    TermOpening::factory()->create(['term_id' => $term->id, 'modality_id' => modalityOf('PRES')->id]);
    $career = Career::factory()->create(['code' => 'ISC', 'name' => 'Sistemas Computacionales']);
    CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => $c1->id]);
    $department = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $proposal = CareerProposal::factory()->create(['term_id' => $term->id, 'career_id' => $career->id]);
    $subject = Subject::factory()->create(['code' => 'ACF-0901', 'name' => 'Cálculo Diferencial', 'department_id' => $department->id]);
    ProposalItem::factory()->create(['career_proposal_id' => $proposal->id, 'campus_id' => $c1->id, 'subject_id' => $subject->id, 'semester' => 1, 'requested_groups' => 1, 'captured_at' => now()]);
    ProposalIntake::factory()->create(['career_proposal_id' => $proposal->id, 'campus_id' => $c1->id, 'suggested_new_students' => 30, 'new_students' => 30]);
    User::factory()->withRole(Role::Coordinador, ['campus_id' => $c1->id, 'career_id' => $career->id])->create(['username' => '19110005']);

    $openDrawers = "[...document.querySelectorAll('.drawer-toggle')].filter(c => c.checked).length";

    $page = visit('/login')
        ->fill('username', '19110005')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/academic-load/proposal?vista=tabla')
        ->click('@open-ACF-0901')
        ->assertPresent('@proposal-drawer');

    $page->assertScript($openDrawers, 1);

    // Un punto a la izquierda del panel, sobre el velo del drawer abierto.
    $page->script('document.elementFromPoint(20, 300).click()');
    $page->wait(1);

    $page->assertScript($openDrawers, 0)
        ->assertScript("document.querySelectorAll('.drawer-toggle').length", 2)
        ->assertNoJavaScriptErrors();
});
