<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;

/*
| El shell en el navegador (DESIGN.md, "El menú que se transforma"): la liebre abre el menú con el
| cursor en su buscador; el buscador filtra sin importar acentos y Enter abre la primera entrada; Esc
| lo cierra y regresa el foco a la liebre. La credencial se abre como tarjeta con el campus y el
| periodo. En el celular, la liebre baja el menú como hoja.
*/

beforeEach(function () {
    Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    Term::factory()->current()->create(['name' => 'Ene-Jun 2027']);

    User::factory()->admin()->create(['username' => 'admin', 'name' => 'Ana Admin']);
});

/**
 * Entra como admin y espera a que Alpine arranque el shell: un atajo de teclado que llega antes no
 * tiene quién lo escuche.
 */
function signInToShell()
{
    return visit('/login')
        ->fill('username', 'admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->assertScript('window.Alpine?.$data(document.body).menuOpen === false');
}

it('la liebre abre el menú con el cursor en el buscador; buscar filtra y Esc lo cierra', function () {
    $page = signInToShell()
        ->assertVisible('a[data-label="Campus"]')
        ->assertMissing('a[data-label="Importaciones"]')
        ->click('@hare')
        ->assertScript('document.body.dataset.menu', 'open')
        ->assertAttribute('@hare', 'aria-expanded', 'true')
        ->assertAttribute('@hare', 'aria-label', 'Cerrar el menú completo')
        ->assertVisible('[data-menu-area="datos"] [data-menu-flip="name"]')
        ->assertVisible('a[data-label="Importaciones"]')
        ->assertScript('document.activeElement.id', 'nexus-menu-search');

    $page->type('#nexus-menu-search', 'IMPORTACIÓN')
        ->assertVisible('a[data-label="Importaciones"]')
        ->assertMissing('a[data-label="Campus"]')
        ->assertMissing('[data-menu-area="academico"] [data-menu-flip="name"]')
        ->type('#nexus-menu-search', 'zzz')
        ->assertSee('Sin resultados')
        ->assertMissing('a[data-label="Importaciones"]')
        ->keys('#nexus-menu-search', 'Escape')
        ->assertScript('document.body.dataset.menu', 'closed')
        ->assertAttribute('@hare', 'aria-expanded', 'false')
        ->assertScript('document.activeElement.id', 'nexus-hare')
        ->assertVisible('a[data-label="Campus"]')
        ->assertMissing('a[data-label="Importaciones"]')
        ->assertDontSee('Sin resultados')
        ->assertNoJavaScriptErrors();
});

it('Ctrl K abre el menú y Enter en el buscador abre la primera entrada que queda', function () {
    signInToShell()
        ->keys('@hare', 'Control+k')
        ->assertScript('document.activeElement.id', 'nexus-menu-search')
        ->type('#nexus-menu-search', 'impor')
        ->keys('#nexus-menu-search', 'Enter')
        ->assertPathIs('/imports')
        ->assertScript('document.body.dataset.menu', 'closed')
        ->assertSeeIn('#nexus-menu [aria-current="page"]', 'Importaciones')
        ->assertSeeIn('nav[aria-label="Ruta"]', 'Datos')
        ->assertNoJavaScriptErrors();
});

it('la credencial se abre como tarjeta con el campus y el periodo, y Esc la cierra', function () {
    signInToShell()
        ->assertMissing('select[name="campus"]')
        ->click('@credential')
        ->assertAttribute('@credential', 'aria-expanded', 'true')
        ->assertVisible('select[name="campus"]')
        ->assertVisible('select[name="term"]')
        ->assertSee('Cambiar contraseña')
        ->assertSee('Cerrar sesión')
        ->keys('select[name="term"]', 'Escape')
        ->assertAttribute('@credential', 'aria-expanded', 'false')
        ->assertMissing('select[name="campus"]')
        ->assertScript('document.activeElement.id', 'nexus-credential-toggle')
        ->assertNoJavaScriptErrors();
});

it('en el celular, la liebre baja el menú como hoja con todas las áreas', function () {
    $page = visit('/login')->resize(390, 844);

    $page->fill('username', 'admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->assertScript('window.Alpine?.$data(document.body).menuOpen === false')
        ->assertMissing('#nexus-menu')
        ->assertVisible('button[aria-label="Buscar"]')
        ->click('@hare')
        ->assertVisible('#nexus-menu')
        ->assertSee('Académico')
        ->assertVisible('a[data-label="Campus"]')
        ->assertVisible('a[data-label="Importaciones"]')
        ->assertScript('document.activeElement.id', 'nexus-menu-search')
        ->click('Importaciones')
        ->assertPathIs('/imports')
        ->assertMissing('#nexus-menu')
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth')
        ->assertNoJavaScriptErrors();
});
