<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;

/*
| Bandeja del departamento en el navegador (tarea 2.8): el jefe, con sus catálogos validados, entra desde el menú,
| elige una carrera pendiente y la acepta; la ve «Aceptada» y su carga en «Propuesta».
*/

beforeEach(function () {
    $campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II', 'settings' => [...CampusProfile::DEFAULTS, 'uses_rooms' => false]]);
    $term = Term::factory()->current()->create(['code' => '20271']);
    TermOpening::factory()->create(['term_id' => $term->id, 'campus_id' => $campus->id]);
    $department = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $this->load = AcademicLoad::factory()->create(['term_id' => $term->id, 'campus_id' => $campus->id, 'department_id' => $department->id]);
    foreach (Catalog::general() as $catalog) {
        CatalogReview::factory()->general($catalog)->validated()->create(['term_id' => $term->id, 'department_id' => $department->id]);
    }

    $career = Career::factory()->create(['code' => 'ISC', 'name' => 'Sistemas Computacionales']);
    CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => $campus->id]);
    $proposal = CareerProposal::factory()->submitted()->create(['term_id' => $term->id, 'campus_id' => $campus->id, 'career_id' => $career->id]);
    ProposalItem::factory()->create([
        'career_proposal_id' => $proposal->id,
        'subject_id' => Subject::factory()->create(['code' => 'ACF-0901', 'name' => 'Cálculo Diferencial', 'department_id' => $department->id])->id,
        'requested_groups' => 2,
        'expected_students' => 60,
    ]);
    $this->part = ProposalReview::factory()->create(['career_proposal_id' => $proposal->id, 'department_id' => $department->id]);

    User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $campus->id, 'department_id' => $department->id])->create(['username' => '19110004']);
});

it('el jefe elige una carrera pendiente y la acepta; su carga queda en Propuesta', function () {
    visit('/login')
        ->fill('username', '19110004')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/academic-load/inbox')
        ->assertSee('Bandeja del departamento')
        ->assertSee('Borrador')
        ->click('@filter-pending')
        ->click('[data-career=ISC]')
        ->assertSee('Cálculo Diferencial')
        ->click('@return-part')
        ->assertSee('0 / 1000')
        ->click('Cancelar')
        ->click('@accept-part')
        ->assertSee('Lo aceptado ya no cambia.')
        ->click('@confirm-accept')
        ->assertSee('Aceptada')
        ->assertSee('Propuesta')
        ->assertNoJavaScriptErrors();

    expect($this->part->fresh()->status)->toBe(ProposalReviewStatus::Accepted)
        ->and($this->load->fresh()->status)->toBe(LoadStatus::Proposed);
});
