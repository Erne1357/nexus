<?php

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectPrerequisite;
use App\Modules\Core\Models\User;
use Database\Seeders\DemoSeeder;

/*
| Retícula de un plan en el navegador (tarea 1.8): el admin abre la retícula de un plan sembrado desde la tabla
| de Planes, toca una materia y ve su lugar y su seriación en el panel lateral con su cadena resaltada; Esc
| cierra el panel; desde «Especialidades» da de alta una especialidad y la renombra. Luego agrega una materia del
| catálogo al pie de un semestre, la mueve desde el panel y la quita de la retícula (Esc en la confirmación no
| cierra el panel). Por último, en el panel agrega un requisito: como seriación cierra un ciclo y el panel lo dice
| con su cadena; como correquisito entra; volverlo seriación lo rechaza y el select regresa; y lo quita. La
| retícula es una cuadrícula perfecta: mosaicos del mismo tamaño, renglones alineados entre columnas, nueve
| semestres a 1366 px sin desplazarse, las cadenas debajo y la barra de selección al resaltar una cadena.
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
        ->waitForText('Requisito agregado')
        ->assertSeeIn('[data-testid="requires-section"]', 'Ecuaciones Diferenciales')
        ->assertSeeIn('[data-subject="ACF-0901"]', 'Correq.')
        ->assertNoJavaScriptErrors();

    // Volverlo seriación cierra el ciclo: el panel lo dice y el select regresa a «Correquisito».
    $page->select('[data-requisite="ACF-0905"] select', 'seriacion')
        ->waitForText('Cierra un ciclo')
        ->assertScript($kindOf, 'correquisito')
        ->click('[aria-label="Quitar el requisito ACF-0905"]')
        ->waitForText('Requisito quitado')
        ->assertDontSeeIn('[data-testid="requires-section"]', 'Ecuaciones Diferenciales')
        ->assertNoJavaScriptErrors();

    $calculus = Subject::where('code', 'ACF-0901')->sole();
    $equations = Subject::where('code', 'ACF-0905')->sole();
    expect(SubjectPrerequisite::withTrashed()->where('study_plan_id', $this->plan->id)->where('subject_id', $calculus->id)->where('prerequisite_subject_id', $equations->id)->sole())
        ->kind->toBe(PrerequisiteKind::Correquisito)->trashed()->toBeTrue();
});

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
        ->assertScript("(() => { const band = document.querySelector('[data-band=\"{$software->id}\"]').getBoundingClientRect(); return [...document.querySelectorAll('[data-subject]')].filter((tile) => ".json_encode($softwareCodes->all()).".includes(tile.dataset.subject)).every((tile) => tile.getBoundingClientRect().top > band.bottom); })()", true)
        ->assertScript("document.querySelector('[data-testid=chain-legend]').getBoundingClientRect().top >= document.querySelector('[data-testid=plan-grid]').getBoundingClientRect().bottom", true)
        ->assertNoJavaScriptErrors();

    // Nueve semestres caben a 1366 px sin desplazar la caja, con la clave completa en cada mosaico.
    $page->resize(1366, 900)
        ->assertScript("(() => { const box = document.querySelector('[data-testid=plan-grid]').parentElement; return box.scrollWidth <= box.clientWidth; })()", true)
        ->assertScript("[...document.querySelectorAll('[data-subject] > span:first-child > span:first-child')].every((key) => key.scrollWidth <= key.clientWidth)", true);
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
