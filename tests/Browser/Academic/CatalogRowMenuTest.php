<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\User;

/*
| Menú «Acciones» y tablas de los catálogos de Académico en el navegador, a 1440 px con el menú
| lateral abierto: cada fila de Carreras mide menos de 64 px, el botón «Acciones» de la primera fila de
| Materias y de Planes queda dentro de la caja de su tabla, y el menú de la última fila se ve completo
| en la ventana (se abre hacia arriba si abajo no cabe), con Esc que lo cierra y devuelve el foco.
*/

beforeEach(function () {
    $department = Department::factory()->create(['code' => 'SIS']);

    foreach (range(1, 12) as $n) {
        $career = Career::factory()->create([
            'code' => sprintf('CAR%02d', $n),
            'name' => 'Ingeniería en Sistemas Computacionales '.$n,
            'department_id' => $department->id,
        ]);
        Subject::factory()->create(['code' => sprintf('MAT%02d', $n), 'department_id' => $department->id]);
        StudyPlan::factory()->for($career)->create(['code' => sprintf('PLN-%02d', $n), 'name' => 'Ingeniería en Sistemas Computacionales '.$n]);
    }

    User::factory()->admin()->create(['username' => 'admin', 'name' => 'Ana Admin']);
});

function signedInAt1440(string $path, string $text)
{
    return visit('/login')
        ->resize(1440, 900)
        ->fill('username', 'admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate($path)
        ->assertSee($text);
}

it('en Carreras, a 1440 px, cada fila mide menos de 64 px', function () {
    $page = signedInAt1440('/academic/careers', 'CAR01');

    $tallest = $page->script("Math.max(...[...document.querySelectorAll('table tbody tr')].map((row) => row.getBoundingClientRect().height))");

    expect($tallest)->toBeLessThan(64);
    $page->assertNoJavaScriptErrors();
});

it('en Materias y Planes, a 1440 px, «Acciones» de la primera fila queda dentro de la caja de la tabla', function (string $path, string $code) {
    $page = signedInAt1440($path, $code);

    $inside = $page->script("(() => {
        const button = document.querySelector('table tbody tr button[aria-label=\"Acciones de {$code}\"]').getBoundingClientRect();
        const container = document.querySelector('table').parentElement;
        const box = container.getBoundingClientRect();

        return container.scrollWidth <= container.clientWidth && button.right <= box.right && button.left >= box.left;
    })()");

    expect($inside)->toBeTrue();
    $page->assertNoJavaScriptErrors();
})->with([
    'Materias' => ['/academic/subjects', 'MAT01'],
    'Planes de estudio' => ['/academic/plans', 'PLN-01'],
]);

it('el menú de la última fila se ve completo, Esc lo cierra y el foco vuelve al botón', function () {
    $page = signedInAt1440('/academic/careers', 'CAR01');

    $page->script("document.querySelector('table tbody tr:last-child').scrollIntoView({ block: 'end' })");
    $page->click('table tbody tr:last-child [aria-haspopup="menu"]')->assertSee('Desactivar');

    $fits = $page->script("(() => {
        const menu = document.querySelector('table tbody tr:last-child [role=menu]').getBoundingClientRect();

        return menu.top >= 0 && menu.left >= 0 && menu.bottom <= window.innerHeight && menu.right <= window.innerWidth && menu.height > 80;
    })()");

    expect($fits)->toBeTrue();

    $page->keys('table tbody tr:last-child [role=menu]', 'Escape');

    expect($page->script("document.querySelector('table tbody tr:last-child [role=menu]').matches(':popover-open')"))->toBeFalse()
        ->and($page->script("document.activeElement.getAttribute('aria-haspopup')"))->toBe('menu');
    $page->assertNoJavaScriptErrors();
});
