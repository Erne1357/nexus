<?php

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;

/*
| Un nombre largo en el menú se lee completo (tarea 1.21). Cerrado en escritorio, la columna mide 224 px y la entrada
| activa va en negritas con su punto: «Validación de catálogos», la de Subdirección en la validación abierta, baja a un
| segundo renglón en vez de cortarse en «Validación de catálo…», y las demás entradas siguen en uno de 36 px. Abierto,
| la retícula va en renglones de 36 px y todas caben en uno; en la hoja del celular también.
*/

beforeEach(function () {
    $term = Term::factory()->current()->create();
    TermOpening::factory()->create(['term_id' => $term->id, 'modality_id' => modalityOf('PRES')->id]);
    Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    app(CreateTermReviews::class)->handle($term, Catalog::cases());
    User::factory()->withRole(Role::Subdireccion, [])->create(['username' => 'subdireccion']);
});

/** JS: por cada entrada del menú que se ve, su nombre, su alto y si su nombre se sale de su caja (se corta). */
const MENU_ENTRY_BOXES = '[...document.querySelectorAll("#nexus-menu a[data-menu-entry]")].filter((a) => a.getBoundingClientRect().width > 0).map((a) => { const name = a.querySelector("span"); return { label: a.dataset.label, height: Math.round(a.getBoundingClientRect().height), cut: name.scrollWidth > name.clientWidth }; })';

function signInAsSubdireccionAt(int $width)
{
    return visit('/login')
        ->resize($width, 900)
        ->fill('username', 'subdireccion')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/catalog-validation')
        ->assertPathIs('/catalog-validation')
        ->assertScript('window.Alpine?.$data(document.body).menuOpen === false');
}

it('a 1440 px, cerrado, «Validación de catálogos» activa se lee completa en dos renglones y las demás siguen en uno', function () {
    $page = signInAsSubdireccionAt(1440);
    $closed = collect($page->script(MENU_ENTRY_BOXES))->keyBy('label');

    expect($closed['Validación de catálogos'])->toMatchArray(['cut' => false])
        ->and($closed['Validación de catálogos']['height'])->toBeGreaterThan(36)
        ->and($closed->except('Validación de catálogos')->pluck('height')->unique()->all())->toBe([36])
        ->and($closed->pluck('cut')->unique()->all())->toBe([false]);

    $page->click('@hare')->assertScript('Alpine.$data(document.body).busy === false');
    $open = collect($page->script(MENU_ENTRY_BOXES))->keyBy('label');

    expect($open['Validación de catálogos'])->toMatchArray(['height' => 36, 'cut' => false])
        ->and($open->pluck('height')->unique()->all())->toBe([36]);

    $page->assertNoJavaScriptErrors();
});

it('a 390 px, en la hoja del celular, «Validación de catálogos» se lee completa en un renglón', function () {
    $page = signInAsSubdireccionAt(390)
        ->click('@hare')
        ->assertScript('Alpine.$data(document.body).busy === false');
    $sheet = collect($page->script(MENU_ENTRY_BOXES))->keyBy('label');

    expect($sheet['Validación de catálogos'])->toMatchArray(['height' => 44, 'cut' => false])
        ->and($sheet->pluck('cut')->unique()->all())->toBe([false]);

    $page->assertNoJavaScriptErrors();
});
