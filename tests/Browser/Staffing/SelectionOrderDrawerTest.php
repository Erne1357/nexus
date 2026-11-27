<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\RoleAssignment;
use Tests\Support\Staffing\SelectionWorld;

/*
| El drawer del orden de selección (tarea 1.52) no se desborda a lo ancho: de celular a escritorio sus renglones
| compactos caben en el cuerpo del drawer, sin scroll horizontal. Nombres ficticios.
*/

it('el drawer del orden no tiene scroll horizontal en 390 y 1280 px', function (int $width, int $height) {
    $world = SelectionWorld::create();
    $uno = $world->teacher(base: 5, name: 'Docente Uno con un nombre bastante largo para probar el ajuste de renglón');
    RoleAssignment::factory()->for($uno->user)->create(['role' => Role::Docente]);
    $uno->user->update(['username' => 'docente.orden']);
    $world->teacher(base: 8, position: 2, name: 'Docente Dos');
    $world->group('A', [[1, '09:00', '10:00'], [3, '09:00', '10:00']]);

    $page = visit('/login')
        ->resize($width, $height)
        ->fill('username', 'docente.orden')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/mi-espacio/oferta')
        ->click('[data-testid="selection-order-open"]')
        ->assertVisible('[data-testid="selection-order"]')
        ->assertSee('Docente Dos');

    // El cuerpo del drawer no se desborda y ningún elemento dentro de él trae su propio scroll horizontal.
    $overflow = $page->script('(() => { const b = document.querySelector(\'[data-testid="selection-order"]\'); return b.scrollWidth - b.clientWidth; })()');
    $scrollers = $page->script('(() => [...document.querySelectorAll(\'[data-testid="selection-order"] *\')].filter((e) => [\'auto\', \'scroll\'].includes(getComputedStyle(e).overflowX)).length)()');

    expect($overflow)->toBeLessThanOrEqual(0)->and($scrollers)->toBe(0);
    $page->assertNoJavaScriptErrors();
})->with([[390, 844], [1280, 800]]);
