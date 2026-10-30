<?php

use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\User;
use Database\Seeders\DemoSeeder;

/*
| Retícula de un plan en el navegador (tarea 1.8): el admin abre la retícula de un plan sembrado desde la tabla
| de Planes, toca una materia y ve su lugar y su seriación en el panel lateral con su cadena resaltada; Esc
| cierra el panel; desde «Especialidades» da de alta una especialidad y la renombra. Luego agrega una materia del
| catálogo al pie de un semestre, la mueve desde el panel y la quita de la retícula (Esc en la confirmación no
| cierra el panel).
*/

beforeEach(function () {
    $this->seed(DemoSeeder::class);
    $this->plan = StudyPlan::where('code', 'ISIC-2010-224')->sole();
    User::factory()->admin()->create(['username' => 'ana.admin', 'name' => 'Ana Admin']);
});

it('el admin abre la retícula de un plan, ve una materia en el panel y gestiona sus especialidades', function () {
    $onChain = fn (string $code): string => "document.querySelector('[data-subject=\"{$code}\"]').hasAttribute('data-on-chain')";

    $page = visit('/login')
        ->fill('username', 'ana.admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/academic/plans')
        ->click('table a[aria-label="Retícula de ISIC-2010-224"]')
        ->assertPathIs('/academic/plans/'.$this->plan->id)
        ->assertSee('Cálculo Integral')
        ->click('[data-subject="ACF-0902"]')
        ->waitForText('Lugar en la retícula')
        ->assertSeeIn('[data-testid="requires-section"]', 'Cálculo Diferencial')
        ->assertSeeIn('[data-testid="required-by-section"]', 'Cálculo Vectorial')
        ->assertScript($onChain('ACF-0905'), true)
        ->assertScript($onChain('SCD-1022'), false)
        ->assertNoJavaScriptErrors();

    // El foco está dentro del panel (x-trap); Esc lo cierra.
    $page->keys('[data-testid="subject-panel"] [aria-label="Cerrar el panel"]', 'Escape')
        ->waitForText('Toca una materia para verla en el panel')
        ->assertScript("document.querySelector('[data-testid=subject-panel]') === null", true)
        ->assertScript($onChain('ACF-0905'), false);

    $page->click('@open-specialties')
        ->waitForText('Especialidades del plan')
        ->fill('code', 'isic-iot-2026')
        ->fill('name', 'Internet de las Cosas')
        ->press('Crear especialidad')
        ->waitForText('Especialidad creada')
        ->assertSeeIn('[data-testid="specialties-panel"]', 'ISIC-IOT-2026')
        ->click('@rename-ISIC-IOT-2026')
        ->fill('renamedName', 'Internet de las Cosas Industrial')
        ->press('Guardar')
        ->waitForText('Especialidad renombrada')
        ->assertNoJavaScriptErrors();

    expect(Specialty::where('code', 'ISIC-IOT-2026')->sole())
        ->study_plan_id->toBe($this->plan->id)->name->toBe('Internet de las Cosas Industrial');
});

it('el admin agrega una materia del catálogo a un semestre, la mueve y la quita de la retícula', function () {
    $subject = Subject::query()
        ->where('is_active', true)
        ->whereDoesntHave('studyPlans', fn ($query) => $query->whereKey($this->plan->id))
        ->orderBy('code')
        ->firstOrFail();

    $page = visit('/login')
        ->fill('username', 'ana.admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/academic/plans/'.$this->plan->id)
        ->click('[data-add-semester="9"]')
        ->waitForText('Agregar materia al semestre 9')
        ->type('addSearch', $subject->code)
        ->waitForText($subject->name)
        ->click('[data-catalog="'.$subject->code.'"]')
        ->waitForText('Agregar '.$subject->code)
        ->click('@add-submit')
        ->waitForText('Materia agregada')
        ->assertSeeIn('[data-semester="9"]', $subject->code)
        ->assertNoJavaScriptErrors();

    $page->click('[data-subject="'.$subject->code.'"]')
        ->waitForText('Lugar en la retícula')
        ->select('semester', '8')
        ->press('Guardar lugar')
        ->waitForText('Lugar guardado')
        ->assertSeeIn('[data-semester="8"]', $subject->code)
        ->click('@remove-placement')
        ->waitForText('No tiene seriación')
        // Esc cierra solo la confirmación: el panel sigue abierto con la materia.
        ->keys('@confirm-remove', 'Escape')
        ->click('@remove-placement')
        ->waitForText('No tiene seriación')
        ->click('@confirm-remove')
        ->waitForText('Materia quitada')
        ->assertDontSee('Lugar en la retícula')
        ->assertNoJavaScriptErrors();

    expect(StudyPlanSubject::withTrashed()->where('study_plan_id', $this->plan->id)->where('subject_id', $subject->id)->sole())
        ->semester->toBe(8)->trashed()->toBeTrue();
});
