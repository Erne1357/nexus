<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;

/*
| Ficha del alumno en el navegador (tarea 1.6): el coordinador entra a Alumnos desde el menú, abre la ficha de un
| alumno de su carrera, ve su retícula y pasa al kárdex.
*/

beforeEach(function () {
    $campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $career = Career::factory()->create(['code' => 'ISIC', 'name' => 'Ingeniería en Sistemas Computacionales']);
    $plan = StudyPlan::factory()->for($career)->create(['code' => 'ISIC-2010-224', 'total_credits' => 260]);
    $calculus = Subject::factory()->create(['code' => 'ACF-0901', 'name' => 'Cálculo Diferencial']);
    $integral = Subject::factory()->create(['code' => 'ACF-0902', 'name' => 'Cálculo Integral']);
    placeInPlan($plan, $calculus);
    placeInPlan($plan, $integral, 2);
    seriate($plan, $integral, $calculus);
    $student = Student::factory()->for($plan)->create(['control_number' => '24111234', 'name' => 'Daniela Ortiz Mendoza', 'campus_id' => $campus->id]);
    KardexRecord::factory()->for($student)->for($calculus)->create(['term_code' => '20253', 'grade' => 88]);

    User::factory()->withRole(Role::Coordinador, ['campus_id' => $campus->id, 'career_id' => $career->id])->create(['username' => '19110002']);
});

it('el coordinador abre la ficha de un alumno desde el listado y pasa al kárdex', function () {
    visit('/login')
        ->fill('username', '19110002')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/students')
        ->assertSee('Daniela Ortiz Mendoza')
        ->click('@show-24111234')
        ->assertSee('Cálculo Integral')
        ->assertSee('Puede seleccionarla')
        ->click('@tab-kardex')
        ->assertSee('Ago-Dic 2025')
        ->assertSee('1 · ordinaria')
        ->assertNoJavaScriptErrors();
});

it('al tocar una materia resalta su cadena y la barra de selección dice qué pide y qué abre; Esc la quita y la leyenda resalta la cadena completa', function () {
    $student = Student::where('control_number', '24111234')->sole();
    $focused = "document.querySelector('[data-testid=student-grid]').hasAttribute('data-focus')";
    $onChain = fn (string $code): string => "document.querySelector('[data-subject=\"{$code}\"]').hasAttribute('data-on-chain')";
    $barShown = "(() => { const bar = document.querySelector('[data-testid=selection-bar]'); return bar.hasAttribute('data-shown') && bar.offsetHeight > 0; })()";

    $page = visit('/login')
        ->fill('username', '19110002')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/students/'.$student->id)
        ->assertScript("document.querySelector('[data-testid=selection-bar]').offsetHeight", 0)
        ->assertScript("document.querySelector('[data-testid=chain-info]').textContent.trim()", '')
        ->click('[data-subject="ACF-0902"]')
        ->assertSeeIn('[data-testid="selection-bar"]', 'ACF-0902 Cálculo Integral: pide ACF-0901 Cálculo Diferencial · no abre ninguna')
        ->assertScript($barShown, true)
        ->assertScript($focused, true)
        ->assertScript($onChain('ACF-0901'), true);

    // Tocar la pestaña en que ya está vuelve a pintar la página, pero no pierde el resaltado.
    $page->click('[role=tab]:not([data-testid=tab-kardex])')
        ->assertScript($focused, true);

    // Con el menú abierto, Esc cierra el menú y no quita el resaltado: Esc cierra solo la capa de arriba.
    $page->script("document.body.dataset.menu = 'open'; window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' })); document.body.dataset.menu = 'closed'");
    $page->assertScript($focused, true);

    $page->script("window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))");

    $page->assertScript($focused, false)
        ->assertScript($barShown, false)
        ->assertDontSee('Toca una materia')
        ->click('[data-chain-chip][data-chain="0"]')
        ->assertSeeIn('[data-testid="selection-bar"]', 'Cadena de Cálculo Diferencial: 2 materias.')
        ->assertScript($onChain('ACF-0901'), true)
        ->assertScript($onChain('ACF-0902'), true)
        ->click('[data-testid="selection-bar"] button')
        ->assertScript($focused, false)
        ->assertScript($barShown, false)
        ->assertNoJavaScriptErrors();
});

it('a 1440 px todos los mosaicos miden lo mismo, los de un renglón se alinean entre columnas y las cadenas van debajo de la retícula', function () {
    $student = Student::where('control_number', '24111234')->sole();
    $plan = $student->studyPlan;
    $specialty = Specialty::factory()->for($plan)->create(['name' => 'Ciencia de Datos e Inteligencia Artificial Aplicada']);
    placeInPlan($plan, Subject::factory()->create(['code' => 'ACA-0910', 'name' => 'Taller de Investigación para el Desarrollo de Proyectos de Ingeniería en Sistemas Computacionales']));
    placeInPlan($plan, Subject::factory()->create(['code' => 'DAD-2401', 'name' => 'Aprendizaje Automático Supervisado y No Supervisado']), 2, $specialty);
    $deep = Subject::factory()->create(['code' => 'DAD-2402', 'name' => 'Aprendizaje Profundo para Visión por Computadora y Lenguaje Natural']);
    placeInPlan($plan, $deep, 3, $specialty);
    seriate($plan, $deep, Subject::where('code', 'DAD-2401')->sole());
    $top = fn (string $code): string => "Math.round(document.querySelector('[data-subject=\"{$code}\"]').getBoundingClientRect().top)";

    $page = visit('/login')
        ->fill('username', '19110002')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/students/'.$student->id)
        ->resize(1440, 900)
        ->assertSee('Aprendizaje Profundo')
        ->assertScript("new Set([...document.querySelectorAll('[data-subject]')].map((tile) => { const box = tile.getBoundingClientRect(); return Math.round(box.width) + 'x' + Math.round(box.height); })).size", 1)
        ->assertScript("Math.round(document.querySelector('[data-subject=\"ACA-0910\"]').getBoundingClientRect().height)", 104)
        // Sin requisitos, el nombre largo usa tres renglones (el lugar del de requisitos); con requisitos, dos.
        ->assertScript("Math.round(document.querySelector('[data-subject=\"ACA-0910\"] > [lang=es]').getBoundingClientRect().height)", 48)
        ->assertScript("Math.round(document.querySelector('[data-subject=\"DAD-2402\"] > [lang=es]').getBoundingClientRect().height)", 32)
        ->assertScript("[...document.querySelectorAll('[data-subject] > [lang=es]')].every((name) => name.getBoundingClientRect().bottom <= name.parentElement.querySelector(':scope > span:last-child').getBoundingClientRect().top)", true)
        ->assertScript($top('ACA-0910').' === '.$top('ACF-0902'), true)
        ->assertScript($top('DAD-2401').' === '.$top('DAD-2402'), true)
        ->assertScript("document.querySelector('[data-testid=chain-legend]').getBoundingClientRect().top >= document.querySelector('[data-testid=student-grid]').getBoundingClientRect().bottom", true)
        // La segunda cadena también lleva su franja: cada token chain-N existe en el CSS.
        ->assertScript("document.querySelector('[data-subject=\"DAD-2402\"]').dataset.chain", '1')
        ->assertScript("getComputedStyle(document.querySelector('[data-subject=\"DAD-2402\"]')).boxShadow.includes('inset')", true)
        ->assertScript("[...Array(12).keys()].every((n) => getComputedStyle(document.documentElement).getPropertyValue('--color-chain-' + (n + 1)).trim() !== '')", true)
        // El icono de cada estado de la leyenda queda dentro de su recuadro.
        ->assertScript("[...document.querySelectorAll('[data-testid=standing-legend] svg')].every((icon) => { const i = icon.getBoundingClientRect(), b = icon.parentElement.getBoundingClientRect(); return i.left >= b.left && i.right <= b.right && i.top >= b.top && i.bottom <= b.bottom; })", true)
        ->assertNoJavaScriptErrors();
});

it('la retícula de nueve semestres cabe sin desplazarse desde 1366 px; más angosta se desplaza su caja y no la página', function () {
    $student = Student::where('control_number', '24111234')->sole();
    foreach (range(3, 9) as $semester) {
        placeInPlan($student->studyPlan, Subject::factory()->create(['code' => sprintf('SCC-10%02d', $semester)]), $semester);
    }
    $boxScrolls = "(() => { const box = document.querySelector('[data-testid=student-grid]').parentElement; return box.scrollWidth > box.clientWidth; })()";
    // La clave y el icono de estado caben en el mosaico más angosto.
    $keysFit = "[...document.querySelectorAll('[data-subject] > span:first-child > span:first-child')].every((key) => key.scrollWidth <= key.clientWidth)";

    $page = visit('/login')
        ->fill('username', '19110002')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/students/'.$student->id)
        ->assertSee('Cálculo Integral');

    $page->resize(1366, 900)
        ->assertScript($boxScrolls, false)
        ->assertScript($keysFit, true);

    $page->resize(1024, 900)
        ->assertScript($boxScrolls, true)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true);
});

it('la retícula del alumno con nueve semestres no desborda la página en 390, 768 y 1440 px', function () {
    $student = Student::where('control_number', '24111234')->sole();
    foreach (range(3, 9) as $semester) {
        placeInPlan($student->studyPlan, Subject::factory()->create(), $semester);
    }

    $page = visit('/login')
        ->fill('username', '19110002')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/students/'.$student->id)
        ->assertSee('Cálculo Integral');

    foreach ([390, 768, 1440] as $width) {
        $page->resize($width, 900)
            ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true);
    }
});

it('el listado de alumnos no desborda y «Ver ficha» queda en una línea con nombres largos', function () {
    $student = Student::where('control_number', '24111234')->sole();
    Student::factory()->for($student->studyPlan)->count(5)->create(['campus_id' => $student->campus_id, 'name' => 'Fernanda Holguín Meza']);

    $page = visit('/login')
        ->fill('username', '19110002')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/students')
        ->assertSee('Fernanda Holguín Meza');

    foreach ([390, 768, 1440, 1920] as $width) {
        $page->resize($width, 900)
            ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
            ->screenshot(filename: "students-$width");

        if ($width >= 768) {
            $page->assertScript("(() => { const a = document.querySelector('table [data-testid^=show-]'); const box = a.closest('.overflow-hidden'); return a.getBoundingClientRect().right <= box.getBoundingClientRect().right; })()", true);
        }
    }

    $page->resize(1440, 900)
        ->assertScript("(() => { const a = document.querySelector('table [data-testid^=show-]'); return a.getBoundingClientRect().height < 48; })()", true);
});

it('en celular la leyenda de cadenas va plegada y se abre con un toque; en escritorio siempre está abierta', function () {
    $student = Student::where('control_number', '24111234')->sole();
    $chip = '[data-chain-chip][data-chain="0"]';
    $hidden = fn (string $selector): string => "document.querySelector('{$selector}').offsetParent === null";
    $toggle = '[data-testid="chain-legend-toggle"]';

    $page = visit('/login')
        ->fill('username', '19110002')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/students/'.$student->id)
        ->resize(390, 900)
        ->assertSeeIn($toggle, 'Cadenas de seriación (')
        ->assertScript($hidden($chip), true)
        ->assertScript("document.querySelector('{$toggle}').getAttribute('aria-expanded')", 'false')
        ->click($toggle)
        ->assertScript($hidden($chip), false)
        ->assertScript("document.querySelector('{$toggle}').getAttribute('aria-expanded')", 'true')
        ->click($chip)
        ->assertSee('Cadena de Cálculo Diferencial: 2 materias.')
        ->assertNoJavaScriptErrors();

    $page->resize(1440, 900)
        ->assertScript($hidden($chip), false)
        ->assertScript($hidden($toggle), true);
});
