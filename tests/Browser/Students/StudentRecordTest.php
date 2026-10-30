<?php

use App\Modules\Academic\Models\Career;
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

it('al tocar una materia resalta su cadena y dice qué pide y qué abre; Esc la quita y la leyenda resalta la cadena completa', function () {
    $student = Student::where('control_number', '24111234')->sole();
    $focused = "document.querySelector('[data-testid=student-grid]').hasAttribute('data-focus')";
    $onChain = fn (string $code): string => "document.querySelector('[data-subject=\"{$code}\"]').hasAttribute('data-on-chain')";

    $page = visit('/login')
        ->fill('username', '19110002')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/students/'.$student->id)
        ->click('[data-subject="ACF-0902"]')
        ->assertSee('ACF-0902 Cálculo Integral: pide ACF-0901 Cálculo Diferencial · no abre ninguna')
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
        ->assertSee('Toca una materia o una cadena para resaltarla; Esc la quita.')
        ->click('[data-chain-chip][data-chain="0"]')
        ->assertSee('Cadena de Cálculo Diferencial: 2 materias.')
        ->assertScript($onChain('ACF-0901'), true)
        ->assertScript($onChain('ACF-0902'), true)
        ->assertNoJavaScriptErrors();
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
