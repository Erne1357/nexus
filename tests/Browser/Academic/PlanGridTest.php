<?php

use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Models\User;
use Database\Seeders\DemoSeeder;

/*
| Retícula de un plan en el navegador (tarea 1.8). Cada prueba siembra el demo completo (DemoSeeder) y tarda casi un
| minuto, así que van en tres archivos para que se repartan entre los shards del CI (docs/arquitectura/testing.md,
| «Shards»):
|
| - PlanGridTest: el admin abre la retícula de un plan sembrado desde la tabla de Planes, toca una materia y ve su
|   lugar y su seriación en el panel lateral con su cadena resaltada; Esc cierra el panel; desde «Especialidades» da
|   de alta una especialidad y la renombra. La barra de selección dice la cadena que se toca en la leyenda.
| - PlanGridPanelTest: desde el panel agrega una materia del catálogo al pie de un semestre, la mueve y la quita de
|   la retícula (Esc en la confirmación no cierra el panel); agrega un requisito: como seriación cierra un ciclo y el
|   panel lo dice con su cadena; como correquisito entra; volverlo seriación lo rechaza y el select regresa; y lo quita.
| - PlanGridLayoutTest: la retícula es una cuadrícula perfecta (mosaicos del mismo tamaño, renglones alineados entre
|   columnas, nueve semestres a 1366 px sin desplazarse, las cadenas debajo) y en celular la leyenda va plegada.
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

    // El foco está dentro del panel (x-trap); Esc lo cierra, quita el resaltado y oculta la barra de selección.
    $page->keys('[data-testid="subject-panel"] [aria-label="Cerrar el panel"]', 'Escape')
        ->assertNotPresent('[data-testid="subject-panel"]')
        ->assertScript($onChain('ACF-0905'), false)
        ->assertScript("document.querySelector('[data-testid=selection-bar]').offsetHeight", 0);

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

it('la barra de selección dice la cadena que se toca en la leyenda y Esc la quita', function () {
    $bar = '[data-testid="selection-bar"]';

    visit('/login')
        ->fill('username', 'ana.admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/academic/plans/'.$this->plan->id)
        ->assertScript("document.querySelector('{$bar}').offsetHeight", 0)
        ->click('[data-testid="chain-legend"] [data-chain="0"]')
        ->assertSeeIn($bar, 'Cadena de')
        ->assertScript("document.querySelector('{$bar}').offsetHeight > 0", true)
        ->assertSeeIn($bar, 'Quitar resaltado')
        ->keys('[data-testid="chain-legend"] [data-chain="0"]', 'Escape')
        ->assertScript("document.querySelector('{$bar}').offsetHeight", 0)
        ->assertScript("document.querySelector('[data-testid=plan-grid]').hasAttribute('data-focus')", false)
        ->click('[data-testid="chain-legend"] [data-chain="0"]')
        ->assertScript("document.querySelector('{$bar}').offsetHeight > 0", true)
        ->click("{$bar} button")
        ->assertScript("document.querySelector('{$bar}').offsetHeight", 0)
        ->assertNoJavaScriptErrors();
});
