<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;

/*
| Con el menú cerrado, la franja de iconos se queda fija y solo la columna del área actual se desplaza
| (DESIGN.md, "El menú que se transforma"): con una ventana baja, el admin llega a la última entrada de
| Administración sin que la página ni la franja se muevan. Al cargar, la entrada activa queda a la
| vista; la columna se desvanece donde hay más; y al cerrar el menú regresa a donde estaba.
*/

beforeEach(function () {
    registerDemoCatalog();

    Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    Term::factory()->current()->create(['name' => 'Ene-Jun 2027']);
    User::factory()->admin()->create(['username' => 'admin', 'name' => 'Ana Admin']);
});

/**
 * Entra como admin con una ventana baja de escritorio.
 */
function signInWithShortWindow(int $height = 400)
{
    return visit('/login')
        ->resize(1280, $height)
        ->fill('username', 'admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->assertScript('window.Alpine?.$data(document.body).menuOpen === false');
}

/** JS: dónde está la columna del menú cerrado. */
const MENU_SCROLL_COLUMN = 'document.querySelector("#nexus-menu [data-menu-column]")';

it('se desplaza la columna y no la franja: la última entrada se alcanza y los iconos no se mueven', function () {
    $page = signInWithShortWindow(260)
        ->navigate('/campus')
        ->assertScript('document.body.dataset.menu', 'closed')
        ->assertScript('(() => { const c = '.MENU_SCROLL_COLUMN.'; return c.scrollHeight > c.clientHeight && getComputedStyle(c).overflowY === "auto"; })()', true)
        ->assertScript('getComputedStyle(document.getElementById("nexus-menu")).overflowY', 'visible');

    $page->assertScript('(() => {
        const c = '.MENU_SCROLL_COLUMN.';
        const icon = document.querySelector(\'[data-menu-area="administracion"] [data-menu-flip="icon"]\');
        const before = icon.getBoundingClientRect().top;
        c.scrollTop = c.scrollHeight;
        const last = [...c.querySelectorAll("[data-menu-entry]")].filter((a) => a.offsetParent !== null).pop();

        return last.dataset.label === "Importaciones"
            && last.getBoundingClientRect().bottom <= window.innerHeight
            && icon.getBoundingClientRect().top === before
            && document.documentElement.scrollTop === 0;
    })()', true)
        ->click('@hare')
        ->assertScript('document.body.dataset.menu', 'open')
        ->assertScript('getComputedStyle(document.getElementById("nexus-menu")).overflowY', 'auto')
        ->assertScript('getComputedStyle('.MENU_SCROLL_COLUMN.').display', 'contents')
        ->assertNoJavaScriptErrors();
});

it('al cargar deja a la vista la entrada activa y se desvanece donde hay más, también al llegar por el menú', function () {
    signInWithShortWindow()
        ->navigate('/campus')
        ->assertScript(MENU_SCROLL_COLUMN.'.scrollTop', 0)
        ->click('#nexus-menu a[data-label="Importaciones"]')
        ->assertPathIs('/imports')
        ->assertAttribute('#nexus-menu a[data-label="Importaciones"]', 'aria-current', 'page')
        ->assertScript('(() => {
            const c = '.MENU_SCROLL_COLUMN.';
            const box = c.getBoundingClientRect();
            const active = c.querySelector("[data-active]").getBoundingClientRect();

            return c.scrollTop > 0 && active.top >= box.top && active.bottom <= box.bottom;
        })()', true)
        ->assertScript(MENU_SCROLL_COLUMN.'.hasAttribute("data-fade")', true)
        ->assertScript(MENU_SCROLL_COLUMN.'.style.getPropertyValue("--fade-top")', '32px')
        ->assertScript(MENU_SCROLL_COLUMN.'.style.getPropertyValue("--fade-bottom")', '0px')
        ->assertScript('getComputedStyle('.MENU_SCROLL_COLUMN.').maskImage.startsWith("linear-gradient")', true)
        ->assertNoJavaScriptErrors();
});

it('al cerrar el menú, la columna regresa a donde estaba', function () {
    signInWithShortWindow()
        ->navigate('/campus')
        ->assertScript('window.Alpine?.$data(document.body).menuOpen === false')
        ->assertScript('(() => { '.MENU_SCROLL_COLUMN.'.scrollTop = 120; return '.MENU_SCROLL_COLUMN.'.scrollTop; })()', 120)
        ->click('@hare')
        ->assertScript('document.body.dataset.menu', 'open')
        ->assertScript('Alpine.$data(document.body).busy === false')
        ->keys('#nexus-menu-search', 'Escape')
        ->assertScript('document.body.dataset.menu', 'closed')
        ->assertScript(MENU_SCROLL_COLUMN.'.scrollTop', 120)
        ->assertNoJavaScriptErrors();
});
