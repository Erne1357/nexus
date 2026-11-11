<?php

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectPrerequisite;
use App\Modules\Core\Models\User;
use Database\Seeders\DemoSeeder;

/*
| Retícula de un plan en el navegador (tarea 1.8): cambios desde el panel de la materia. El resto de la retícula y por
| qué va en tres archivos: PlanGridTest.
*/

// En CI cada ida y vuelta de Livewire puede pasar de los 5 s que el plugin reintenta una aserción: este archivo
// espera hasta 20 s y lo devuelve al valor por defecto al terminar.
beforeEach(function () {
    pest()->browser()->timeout(20_000);
    $this->seed(DemoSeeder::class);
    $this->plan = StudyPlan::where('code', 'ISIC-2010-224')->sole();
    User::factory()->admin()->create(['username' => 'ana.admin', 'name' => 'Ana Admin']);
});

afterEach(fn () => pest()->browser()->timeout(5_000));

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

    // El select del panel se llena con la respuesta de Livewire: se espera su valor (el semestre 9 donde se agregó)
    // antes de elegir y se confirma la elección antes de guardar, o un morph tardío la pisa y «Guardar lugar»
    // guarda el semestre de antes.
    $semesterSelect = "document.querySelector('[data-testid=\"subject-panel\"] select[name=\"semester\"]').value";

    $page->click('[data-subject="'.$subject->code.'"]')
        ->waitForText('Lugar en la retícula')
        ->assertScript($semesterSelect, '9')
        ->select('semester', '8')
        ->assertScript($semesterSelect, '8')
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
        ->waitForText('ya no está en la retícula')
        ->assertNotPresent('[data-subject="'.$subject->code.'"]')
        ->assertDontSee('Lugar en la retícula')
        ->assertNoJavaScriptErrors();

    expect(StudyPlanSubject::withTrashed()->where('study_plan_id', $this->plan->id)->where('subject_id', $subject->id)->sole())
        ->semester->toBe(8)->trashed()->toBeTrue();
});

it('el admin agrega un requisito, ve el error de ciclo con su cadena, cambia su tipo y lo quita', function () {
    $kindOf = "document.querySelector('[data-requisite=\"ACF-0905\"] select').value";

    $page = visit('/login')
        ->fill('username', 'ana.admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/academic/plans/'.$this->plan->id)
        ->click('[data-subject="ACF-0901"]')
        ->waitForText('Agregar requisito')
        ->type('requisiteSearch', 'ecuaciones')
        ->waitForText('Ecuaciones Diferenciales')
        ->click('[data-requisite-option="ACF-0905"]')
        ->click('@add-requisite-submit')
        ->waitForText('Cierra un ciclo')
        ->assertSeeIn('@cycle-error', 'ACF-0905 ya pide, en cadena, a ACF-0901.')
        ->assertSeeIn('@cycle-path', 'ACF-0904')
        ->select('kind', 'correquisito')
        ->click('@add-requisite-submit')
        ->assertSeeIn('[data-testid="requires-section"]', 'Ecuaciones Diferenciales')
        ->assertSeeIn('[data-subject="ACF-0901"]', 'Correq.')
        ->assertNoJavaScriptErrors();

    // Volverlo seriación cierra el ciclo: el panel lo dice y el select regresa a «Correquisito».
    $page->select('[data-requisite="ACF-0905"] select', 'seriacion')
        ->waitForText('Cierra un ciclo')
        ->assertScript($kindOf, 'correquisito')
        ->click('[aria-label="Quitar el requisito ACF-0905"]')
        ->assertNotPresent('[data-requisite="ACF-0905"]')
        ->assertDontSeeIn('[data-testid="requires-section"]', 'Ecuaciones Diferenciales')
        ->assertNoJavaScriptErrors();

    $calculus = Subject::where('code', 'ACF-0901')->sole();
    $equations = Subject::where('code', 'ACF-0905')->sole();
    expect(SubjectPrerequisite::withTrashed()->where('study_plan_id', $this->plan->id)->where('subject_id', $calculus->id)->where('prerequisite_subject_id', $equations->id)->sole())
        ->kind->toBe(PrerequisiteKind::Correquisito)->trashed()->toBeTrue();
});
