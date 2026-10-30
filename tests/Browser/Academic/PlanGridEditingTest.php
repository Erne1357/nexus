<?php

use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectPrerequisite;
use App\Modules\Core\Models\User;
use Database\Seeders\DemoSeeder;

/*
| Historia completa de la edición de una retícula (tarea 1.8): el admin entra a Planes, abre la retícula de
| ISIC-2010-224, pone una materia del catálogo en el semestre 3, le agrega como requisito una del semestre 2,
| intenta cerrar un ciclo y ve el mensaje, quita la materia y comprueba que su seriación ya no aparece (ni en
| «La piden» de la otra). A 390 px la página no desborda.
*/

it('el admin edita una retícula de punta a punta: pone, seria, intenta un ciclo y quita', function () {
    $this->seed(DemoSeeder::class);
    $plan = StudyPlan::where('code', 'ISIC-2010-224')->sole();
    User::factory()->admin()->create(['username' => 'ana.admin', 'name' => 'Ana Admin']);

    $subject = Subject::query()
        ->where('is_active', true)
        ->whereDoesntHave('studyPlans', fn ($query) => $query->whereKey($plan->id))
        ->orderBy('code')
        ->firstOrFail();

    $page = visit('/login')
        ->fill('username', 'ana.admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/academic/plans')
        ->click('table a[aria-label="Retícula de ISIC-2010-224"]')
        ->assertPathIs('/academic/plans/'.$plan->id)
        ->click('[data-add-semester="3"]')
        ->waitForText('Agregar materia al semestre 3')
        ->type('addSearch', $subject->code)
        ->waitForText($subject->name)
        ->click('[data-catalog="'.$subject->code.'"]')
        ->waitForText('Agregar '.$subject->code)
        ->click('@add-submit')
        ->waitForText('Materia agregada')
        ->assertSeeIn('[data-semester="3"]', $subject->code)
        // Le agrega como requisito Cálculo Integral (semestre 2).
        ->click('[data-subject="'.$subject->code.'"]')
        ->waitForText('Agregar requisito')
        ->type('requisiteSearch', 'integral')
        ->waitForText('Cálculo Integral')
        ->click('[data-requisite-option="ACF-0902"]')
        ->click('@add-requisite-submit')
        ->waitForText('Requisito agregado')
        ->assertSeeIn('[data-testid="requires-section"]', 'Cálculo Integral')
        ->assertNoJavaScriptErrors();

    // Desde Cálculo Integral, pedir la materia nueva cerraría un ciclo.
    $page->keys('[data-testid="subject-panel"] [aria-label="Cerrar el panel"]', 'Escape')
        ->waitForText('Toca una materia para verla en el panel')
        ->click('[data-subject="ACF-0902"]')
        ->waitForText('Agregar requisito')
        ->assertSeeIn('[data-testid="required-by-section"]', $subject->code)
        ->type('requisiteSearch', $subject->code)
        ->waitForText($subject->name)
        ->click('[data-requisite-option="'.$subject->code.'"]')
        ->click('@add-requisite-submit')
        ->waitForText('Cierra un ciclo')
        ->assertSeeIn('@cycle-error', $subject->code)
        ->assertNoJavaScriptErrors();

    // Quita la materia: su seriación se resuelve con ella y ya no aparece en «La piden» de la otra.
    $page->keys('[data-testid="subject-panel"] [aria-label="Cerrar el panel"]', 'Escape')
        ->waitForText('Toca una materia para verla en el panel')
        ->click('[data-subject="'.$subject->code.'"]')
        ->waitForText('Lugar en la retícula')
        ->click('@remove-placement')
        ->waitForText('Se va con ella')
        ->assertSeeIn('[data-testid="seriation-gone"]', 'Cálculo Integral')
        ->click('@confirm-remove')
        ->waitForText('Materia quitada')
        ->click('[data-subject="ACF-0902"]')
        ->waitForText('Lugar en la retícula')
        ->assertDontSeeIn('[data-testid="required-by-section"]', $subject->code)
        ->resize(390, 844)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoJavaScriptErrors();

    expect(SubjectPrerequisite::where('study_plan_id', $plan->id)->where('subject_id', $subject->id)->count())->toBe(0);
});
