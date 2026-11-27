<?php

use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\StudyPlanSubjectPending;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\User;

/*
| «Por validar» de punta a punta (D18 y D19): el jefe del Departamento de Servicios Escolares entra a Planes, sigue
| «2 por validar» del plan, elige una materia de la bandeja, la coloca en el semestre 2 y la confirma en el panel;
| la materia sale de la bandeja y aparece en la retícula. A 390 px la bandeja va sobre los semestres sin desbordar.
*/

it('el jefe de Servicios Escolares coloca una materia por validar en la retícula', function () {
    $services = OrgUnit::factory()->create(['code' => OrgTree::SCHOOL_SERVICES, 'name' => 'Departamento de Servicios Escolares', 'kind' => OrgUnitKind::Departamento]);
    User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $services->id])->create(['username' => 'sara.escolar', 'name' => 'Sara Escolar']);
    $plan = StudyPlan::factory()->create(['code' => 'IIND-2004', 'name' => 'Ingeniería Industrial', 'semesters_count' => 9]);
    placeInPlan($plan, Subject::factory()->create(['code' => 'ACF-0901', 'name' => 'Cálculo Diferencial', 'credits' => 5]), 2);
    $modern = StudyPlanSubjectPending::factory()->for($plan)
        ->for(Subject::factory()->create(['code' => 'AE22', 'name' => 'Física Moderna', 'theory_hours' => 3, 'practice_hours' => 2, 'credits' => 5]), 'subject')
        ->create(['sii_grid' => '3/5']);
    StudyPlanSubjectPending::factory()->for($plan)->for(Subject::factory()->create(['code' => 'AE9', 'name' => 'Dibujo Electrónico']), 'subject')->create();

    $page = visit('/login')
        ->fill('username', 'sara.escolar')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/academic/plans')
        ->click('table [data-pending-plan="IIND-2004"]')
        ->assertPathIs('/academic/plans/'.$plan->id)
        ->waitForText('Dibujo Electrónico')
        ->assertSeeIn('[data-testid="view-pending"]', '2')
        ->click('[data-pending="AE22"]')
        ->waitForText('Colocar aquí')
        ->click('[data-place-semester="2"]')
        ->waitForText('Semestre 2: 1 materia · 5 cr')
        ->assertSeeIn('[data-testid="pending-facts"]', '3/5')
        ->click('@pending-submit')
        ->waitForText('«Física Moderna» agregada al semestre 2')
        ->assertSeeIn('[data-testid="pending-grid"] [data-semester="2"]', 'Física Moderna')
        ->assertNotPresent('[data-pending="AE22"]')
        ->assertSeeIn('[data-testid="view-pending"]', '1')
        ->assertNoJavaScriptErrors();

    expect(StudyPlanSubject::where('study_plan_id', $plan->id)->where('subject_id', $modern->subject_id)->sole()->semester)->toBe(2)
        ->and($modern->fresh()->resolved_at)->not->toBeNull();

    $page->resize(390, 844)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertScript("(() => { const tray = document.querySelector('[data-testid=pending-tray]').getBoundingClientRect(); const grid = document.querySelector('[data-testid=pending-grid]').getBoundingClientRect(); return tray.bottom <= grid.top; })()", true)
        ->assertNoJavaScriptErrors();
});
