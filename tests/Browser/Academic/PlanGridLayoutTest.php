<?php

use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\User;
use Database\Seeders\DemoSeeder;

/*
| Retícula de un plan en el navegador (tarea 1.8): la cuadrícula a 1440 y 1366 px y la leyenda de cadenas en celular.
| El resto de la retícula y por qué va en tres archivos: PlanGridTest.
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

it('en celular la leyenda de cadenas del plan va plegada y se abre con un toque; en escritorio siempre está abierta', function () {
    $chip = '[data-testid="chain-legend"] [data-chain="0"]';
    $toggle = '[data-testid="chain-legend-toggle"]';
    $hidden = fn (string $selector): string => "document.querySelector('{$selector}').offsetParent === null";

    $page = visit('/login')
        ->fill('username', 'ana.admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/academic/plans/'.$this->plan->id)
        ->resize(390, 900)
        ->assertSeeIn($toggle, 'Cadenas de seriación (')
        ->assertScript($hidden($chip), true)
        ->assertScript("document.querySelector('{$toggle}').getAttribute('aria-expanded')", 'false')
        ->click($toggle)
        ->assertScript($hidden($chip), false)
        ->assertScript("document.querySelector('{$toggle}').getAttribute('aria-expanded')", 'true')
        ->click($chip)
        ->waitForText('Cadena de')
        ->assertScript("document.querySelector('{$chip}').getAttribute('aria-pressed')", 'true')
        ->assertScript($hidden($chip), false)
        ->assertNoJavaScriptErrors();

    $page->resize(1440, 900)
        ->assertScript($hidden($chip), false)
        ->assertScript($hidden($toggle), true);
});

it('a 1440 px todos los mosaicos miden lo mismo, los de un renglón se alinean entre columnas y las cadenas van debajo', function () {
    $software = Specialty::where('study_plan_id', $this->plan->id)->where('name', 'Ingeniería de Software')->sole();
    $softwareCodes = Subject::whereIn('id', StudyPlanSubject::where('specialty_id', $software->id)->orderBy('semester')->pluck('subject_id'))->orderBy('code')->pluck('code');

    $page = visit('/login')
        ->fill('username', 'ana.admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/academic/plans/'.$this->plan->id)
        ->resize(1440, 900)
        ->assertSee('Cálculo Integral')
        ->assertScript("new Set([...document.querySelectorAll('[data-subject]')].map((tile) => { const box = tile.getBoundingClientRect(); return Math.round(box.width) + 'x' + Math.round(box.height); })).size", 1)
        ->assertScript("Math.round(document.querySelector('[data-subject]').getBoundingClientRect().height)", 104)
        // El primer mosaico de cada semestre, en el mismo renglón de todas las columnas.
        ->assertScript("new Set([...document.querySelectorAll('[data-testid=plan-grid] > section')].map((semester) => Math.round(semester.querySelector('[data-subject]').getBoundingClientRect().top))).size", 1)
        // Cada especialidad empieza en el mismo renglón en todas sus columnas, debajo de su banda.
        ->assertScript("(() => { const band = document.querySelector('[data-band=\"{$software->id}\"]').getBoundingClientRect(); return [...document.querySelectorAll('[data-subject]')].filter((tile) => ".json_encode($softwareCodes->all()).'.includes(tile.dataset.subject)).every((tile) => tile.getBoundingClientRect().top > band.bottom); })()', true)
        ->assertScript("document.querySelector('[data-testid=chain-legend]').getBoundingClientRect().top >= document.querySelector('[data-testid=plan-grid]').getBoundingClientRect().bottom", true)
        ->assertNoJavaScriptErrors();

    // Nueve semestres caben a 1366 px sin desplazar la caja, con la clave completa en cada mosaico.
    $page->resize(1366, 900)
        ->assertScript("(() => { const box = document.querySelector('[data-testid=plan-grid]').parentElement; return box.scrollWidth <= box.clientWidth; })()", true)
        ->assertScript("[...document.querySelectorAll('[data-subject] > span:first-child > span:first-child')].every((key) => key.scrollWidth <= key.clientWidth)", true);
});
