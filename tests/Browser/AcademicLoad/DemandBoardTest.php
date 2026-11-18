<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Demand\Models\DemandForecast;

/*
| «Demanda por materia» en el navegador (tarea 5.7): el jefe entra desde el menú, filtra «Con diferencia» y abre el
| drawer de una materia; el coordinador ve la lente de su carrera, de solo consulta.
*/

beforeEach(function () {
    $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    TermOpening::factory()->create(['term_id' => $term->id, 'modality_id' => modalityOf('PRES')->id]);
    $sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    AcademicLoad::factory()->create(['term_id' => $term->id, 'modality_id' => modalityOf('PRES')->id, 'department_id' => $sc->id]);

    $isic = Career::factory()->create(['code' => 'ISIC', 'name' => 'Ingeniería en Sistemas Computacionales']);
    $ige = Career::factory()->create(['code' => 'IGE', 'name' => 'Ingeniería en Gestión Empresarial']);
    foreach ([$isic, $ige] as $career) {
        CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => $c1->id]);
        CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => $c2->id]);
    }

    $topics = Subject::factory()->create(['code' => 'SCD-1027', 'name' => 'Tópicos Avanzados de Programación', 'department_id' => $sc->id, 'needs_lab' => true]);
    $web = Subject::factory()->create(['code' => 'AEB-1055', 'name' => 'Programación Web', 'department_id' => $sc->id]);
    $calculus = Subject::factory()->create(['code' => 'ACF-0901', 'name' => 'Cálculo Diferencial', 'department_id' => $cb->id]);
    $forecast = fn (Career $career, Subject $subject, Campus $campus, int $groups) => DemandForecast::factory()->create([
        'term_id' => $term->id, 'modality_id' => modalityOf('PRES')->id, 'career_id' => $career->id, 'subject_id' => $subject->id, 'campus_id' => $campus->id, 'suggested_groups' => $groups,
    ]);
    $forecast($isic, $topics, $c1, 2);
    $forecast($isic, $web, $c1, 3);
    $forecast($ige, $topics, $c2, 1);
    $forecast($isic, $calculus, $c1, 4);

    $proposal = CareerProposal::factory()->inStatus(ProposalStatus::Submitted)->create(['term_id' => $term->id, 'career_id' => $isic->id]);
    ProposalItem::factory()->create(['career_proposal_id' => $proposal->id, 'campus_id' => $c1->id, 'subject_id' => $topics->id, 'requested_groups' => 4]);
    ProposalItem::factory()->create(['career_proposal_id' => $proposal->id, 'campus_id' => $c1->id, 'subject_id' => $web->id, 'requested_groups' => 3]);
    ProposalItem::factory()->create(['career_proposal_id' => $proposal->id, 'campus_id' => $c1->id, 'subject_id' => $calculus->id, 'requested_groups' => 4]);

    User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $sc->id])->create(['username' => '19110004']);
    User::factory()->withRole(Role::Coordinador, ['campus_id' => $c1->id, 'career_id' => $isic->id])->create(['username' => '19110005']);
});

it('el jefe entra desde el menú, filtra con diferencia y abre el drawer de una materia', function () {
    visit('/login')
        ->fill('username', '19110004')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/academic-load/inbox')
        ->click('Demanda por materia')
        ->assertPathIs('/academic-load/demand')
        ->assertSee('Sistemas y Computación')
        ->assertSee('Propuestas enviadas:')
        ->assertSee('AEB-1055')
        ->click('@demand-filter-diferencia')
        ->assertQueryStringHas('filtro', 'diferencia')
        ->assertDontSee('AEB-1055')
        ->click('[data-testid="demand-row-SCD-1027"] button')
        ->assertSee('Por campus')
        ->assertSee('Por carrera')
        ->assertSee('Historia de la materia en Ene-Jun')
        ->assertQueryStringHas('materia', 'SCD-1027')
        ->click('@demand-drawer-close')
        ->assertQueryStringMissing('materia')
        ->assertNoJavaScriptErrors();
});

it('el coordinador ve la lente de su carrera, de solo consulta', function () {
    visit('/login')
        ->fill('username', '19110005')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/academic-load/demand')
        ->assertSee('Solo consulta')
        ->assertSee('Ingeniería en Sistemas Computacionales')
        ->assertSee('ACF-0901')
        ->assertSee('SCD-1027')
        ->assertNoJavaScriptErrors();
});
