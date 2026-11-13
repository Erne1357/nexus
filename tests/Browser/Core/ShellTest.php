<?php

use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;

/*
| El shell en el navegador (DESIGN.md, "El menú que se transforma"): la liebre abre el menú con el
| cursor en su buscador; el buscador filtra sin importar acentos, esconde los grupos y las áreas sin
| coincidencias y reacomoda lo que queda; ↓ y ↑ recorren las entradas y Enter abre la primera; Esc lo
| cierra y regresa el foco a la liebre. La credencial se abre como tarjeta con el campus y el periodo.
| En el celular, la liebre baja el menú como hoja, con Inicio en su primer renglón. En Inicio, la
| columna cerrada muestra la primera área del admin (Académico); Administración se ve al abrir.
*/

beforeEach(function () {
    Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $term = Term::factory()->current()->create(['name' => 'Ene-Jun 2027']);
    // Carga en curso (MN-04): así las pantallas de la carga están disponibles y el menú no lleva la insignia
    // «Oculta»; lo oculto para los demás lo prueba MenuHiddenTest.
    AcademicLoad::factory()->for($term)->create();

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

it('cada página llega con su propia sesión: lo que otra dejó pendiente no la manda a otro lado al entrar', function () {
    visit('/imports')->assertPathIs('/login');

    signInToShell()->assertNoJavaScriptErrors();
});

it('la liebre abre el menú con el cursor en el buscador; buscar filtra por grupos y Esc lo cierra', function () {
    $page = signInToShell()
        ->assertVisible('a[data-label="Validar catálogos"]')
        ->assertVisible('[data-menu-group="validacion"]')
        ->assertMissing('a[data-label="Importaciones"]')
        ->click('@hare')
        ->assertScript('document.body.dataset.menu', 'open')
        ->assertAttribute('@hare', 'aria-expanded', 'true')
        ->assertAttribute('@hare', 'aria-label', 'Cerrar el menú completo')
        ->assertVisible('[data-menu-area="administracion"] [data-menu-flip="name"]')
        ->assertVisible('[data-menu-group="datos"]')
        ->assertVisible('a[data-label="Importaciones"]')
        ->assertScript('document.activeElement.id', 'nexus-menu-search');

    // Quedan Importaciones con el encabezado de su grupo; se van los otros grupos, las otras áreas e Inicio.
    $page->type('#nexus-menu-search', 'IMPORTACIÓN')
        ->assertVisible('a[data-label="Importaciones"]')
        ->assertVisible('[data-menu-group="datos"]')
        ->assertMissing('[data-menu-group="catalogos"]')
        ->assertMissing('a[data-label="Campus"]')
        ->assertMissing('[data-menu-area="academico"] [data-menu-flip="name"]')
        ->assertMissing('[data-menu-area="academico"] [data-menu-flip="icon"]')
        ->assertMissing('[data-menu-home]')
        ->type('#nexus-menu-search', 'zzz')
        ->assertSee('Sin resultados')
        ->assertMissing('a[data-label="Importaciones"]')
        ->keys('#nexus-menu-search', 'Escape')
        ->assertScript('document.body.dataset.menu', 'closed')
        ->assertAttribute('@hare', 'aria-expanded', 'false')
        ->assertScript('document.activeElement.id', 'nexus-hare')
        ->assertVisible('a[data-label="Validar catálogos"]')
        ->assertVisible('[data-menu-home]')
        ->assertMissing('a[data-label="Importaciones"]')
        ->assertDontSee('Sin resultados')
        ->assertNoJavaScriptErrors();
});

it('lo que deja el buscador sube sin huecos, y con el buscador vacío la retícula del navegador es la del servidor', function () {
    $page = signInToShell()->click('@hare');

    // El lugar de cada elemento del menú en las dos retículas, tal como lo dejó el servidor o el buscador.
    $page->script(<<<'JS'
        (() => {
            window.menuGrid = () => Object.fromEntries([...document.querySelectorAll('#nexus-menu [data-menu-flip]')]
                .filter((item) => item.style.getPropertyValue('--oc') !== '')
                .map((item, index) => [
                    `${index}:${item.dataset.menuFlip}:${item.dataset.label ?? item.dataset.menuGroup ?? item.textContent.trim() ?? ''}`,
                    ['--oc', '--or', '--os', '--oc3', '--or3', '--os3'].map((name) => item.style.getPropertyValue(name).trim()).join(' '),
                ]));
            window.serverGrid = menuGrid();
        })()
    JS);

    $page->type('#nexus-menu-search', 'importac')
        // Administración queda sola: primera columna, su nombre abarca solo una y el grupo Datos va arriba.
        ->assertScript('getComputedStyle(document.querySelector(\'[data-menu-area="administracion"] [data-menu-flip="name"]\')).getPropertyValue("--oc").trim()', '1')
        ->assertScript('document.querySelector(\'[data-menu-area="administracion"] [data-menu-flip="name"]\').style.getPropertyValue("--os").trim()', '1')
        ->assertScript('document.querySelector(\'[data-menu-group="datos"]\').style.getPropertyValue("--or").trim()', '4')
        ->assertScript('document.querySelector(\'a[data-label="Importaciones"]\').getBoundingClientRect().left < 120')
        ->assertScript('JSON.stringify(menuGrid()) !== JSON.stringify(serverGrid)');

    $page->script('Alpine.$data(document.body).filterMenu("")');

    $page->assertScript('JSON.stringify(menuGrid()) === JSON.stringify(serverGrid)')
        ->assertScript('Object.keys(serverGrid).length > 30')
        ->assertNoJavaScriptErrors();
});

it('con el menú abierto, ↓ y ↑ recorren las entradas que quedan y Enter en el buscador abre la primera', function () {
    $page = signInToShell()
        ->click('@hare')
        ->assertScript('document.activeElement.id', 'nexus-menu-search')
        ->keys('#nexus-menu-search', 'ArrowDown')
        ->assertScript('document.activeElement.dataset.label', 'Validar catálogos')
        ->keys('a[data-label="Validar catálogos"]', 'ArrowDown')
        ->assertScript('document.activeElement.dataset.label', 'Avance de validación')
        ->keys('a[data-label="Avance de validación"]', 'ArrowUp')
        ->keys('a[data-label="Validar catálogos"]', 'ArrowUp')
        ->assertScript('document.activeElement.dataset.label', 'Importaciones');

    $page->click('#nexus-menu-search')
        ->type('#nexus-menu-search', 'carga')
        ->keys('#nexus-menu-search', 'ArrowDown')
        ->assertScript('document.activeElement.dataset.label', 'Carga por docente')
        ->keys('a[data-label="Carga por docente"]', 'ArrowDown')
        ->assertScript('document.activeElement.dataset.label', 'Cierre de la carga')
        ->keys('a[data-label="Cierre de la carga"]', 'ArrowDown')
        ->assertScript('document.activeElement.dataset.label', 'Carga por docente')
        ->click('#nexus-menu-search')
        ->keys('#nexus-menu-search', 'Enter')
        ->assertPathIs('/carga-por-docente')
        ->assertScript('document.body.dataset.menu', 'closed')
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
        ->assertSeeIn('nav[aria-label="Ruta"]', 'Administración')
        ->assertNoJavaScriptErrors();
});

it('Inicio también se busca: «inicio» lo deja a la vista y Enter lleva a él', function () {
    // A Importaciones por el buscador, como en la prueba de Ctrl K: así Alpine ya está listo al buscar Inicio.
    signInToShell()
        ->keys('@hare', 'Control+k')
        ->type('#nexus-menu-search', 'impor')
        ->keys('#nexus-menu-search', 'Enter')
        ->assertPathIs('/imports')
        ->assertScript('document.body.dataset.menu', 'closed')
        ->keys('@hare', 'Control+k')
        ->assertScript('document.activeElement.id', 'nexus-menu-search')
        ->type('#nexus-menu-search', 'inicio')
        ->assertVisible('#nexus-menu [data-menu-home]')
        ->assertScript("document.querySelector('#nexus-menu [data-menu-empty]').hidden", true)
        ->keys('#nexus-menu-search', 'Enter')
        ->assertPathIs('/')
        ->assertNoJavaScriptErrors();
});

it('la credencial se abre como tarjeta con el periodo, sin campus, y Esc la cierra', function () {
    signInToShell()
        ->assertMissing('select[name="term"]')
        ->click('@credential')
        ->assertAttribute('@credential', 'aria-expanded', 'true')
        ->assertScript('document.activeElement.id', 'nexus-credential')
        ->assertVisible('select[name="term"]')
        ->assertNotPresent('select[name="campus"]')
        ->assertSee('Cambiar contraseña')
        ->assertSee('Cerrar sesión')
        ->keys('select[name="term"]', 'Escape')
        ->assertAttribute('@credential', 'aria-expanded', 'false')
        ->assertMissing('select[name="term"]')
        ->assertScript('document.activeElement.id', 'nexus-credential-toggle')
        ->assertNoJavaScriptErrors();
});

it('en el celular, la liebre baja el menú como hoja: el buscador, Inicio y todas las áreas con sus grupos', function () {
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
        ->assertVisible('[data-menu-home]')
        ->assertSeeIn('[data-menu-home]', 'Inicio')
        ->assertAttribute('[data-menu-home]', 'aria-current', 'page')
        ->assertScript('document.querySelector("#nexus-menu-search").getBoundingClientRect().bottom <= document.querySelector("[data-menu-home]").getBoundingClientRect().top')
        ->assertScript('document.querySelector("[data-menu-home]").getBoundingClientRect().bottom <= document.querySelector(\'[data-menu-area="academico"] [data-menu-flip="name"]\').getBoundingClientRect().top')
        ->assertVisible('[data-menu-group="catalogos"]')
        ->assertMissing('[data-menu-area="academico"] [data-menu-flip="icon"]')
        ->assertVisible('a[data-label="Campus"]')
        ->assertVisible('a[data-label="Importaciones"]')
        ->assertScript('document.activeElement.id', 'nexus-menu-search')
        ->click('Importaciones')
        ->assertPathIs('/imports')
        ->assertMissing('#nexus-menu')
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth')
        ->assertNoJavaScriptErrors();
});

it('Esc cierra solo la capa de arriba, y lo que se pide a medio movimiento se hace al terminar', function () {
    $page = signInToShell()
        ->click('@hare')
        ->assertScript('document.activeElement.id', 'nexus-menu-search')
        ->click('@credential')
        ->assertScript('document.activeElement.id', 'nexus-credential')
        ->keys('#nexus-credential', 'Escape')
        ->assertAttribute('@credential', 'aria-expanded', 'false')
        ->assertScript('document.activeElement.id', 'nexus-credential-toggle')
        ->assertScript('document.body.dataset.menu', 'open')
        ->assertScript('Alpine.$data(document.body).busy === false');

    // Se cierra y, mientras lo que se va se desvanece, se vuelve a tocar la liebre.
    $page->script('(() => {
        window.menuStates = [];
        new MutationObserver(() => window.menuStates.push(document.body.dataset.menu))
            .observe(document.body, { attributeFilter: ["data-menu"] });
        const shell = Alpine.$data(document.body);
        shell.closeMenu();
        setTimeout(() => shell.toggleMenu(), 50);
    })()');

    $page->assertScript('window.menuStates.join(",")', 'closed,open')
        ->assertScript('document.activeElement.id', 'nexus-menu-search')
        ->assertNoJavaScriptErrors();
});
