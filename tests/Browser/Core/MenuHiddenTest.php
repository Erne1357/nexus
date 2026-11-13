<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;

/*
| Lo oculto para los demás en el navegador (MN-02, opción B «Insignia "Oculta"»). El periodo activo no tiene
| validación abierta ni carga en curso (MN-03 y MN-04): el admin ve esas pantallas con la insignia, el motivo
| flotante al enfocarlas y, en la pantalla misma, el aviso; el coordinador no ve nada de la carga, solo Horarios
| publicados.
*/

beforeEach(function () {
    $c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    Term::factory()->current()->create(['name' => 'Ene-Jun 2027']);

    User::factory()->admin()->create(['username' => 'admin', 'name' => 'Ana Admin']);
    User::factory()
        ->withRole(Role::Coordinador, ['campus_id' => $c2->id, 'career_id' => careerKey(20)])
        ->create(['username' => '19110001', 'name' => 'Carlos Coordinador']);
});

function signInToHiddenMenu(string $username, int $width = 1440)
{
    return visit('/login')
        ->resize($width, 900)
        ->fill('username', $username)
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->assertScript('window.Alpine?.$data(document.body).menuOpen === false');
}

/** JS: el motivo flotante, si se ve, con su texto y su caja; si no, null. */
const MENU_WHY_TIP = '(() => { const t = document.querySelector("[data-menu-why-tip]"); return t.hasAttribute("data-on") ? { text: t.textContent.trim(), box: t.getBoundingClientRect().toJSON() } : null; })()';

it('el admin ve lo oculto con su insignia y su motivo, y el aviso en la pantalla', function () {
    $why = 'Oculta para los demás: No hay carga en curso en Ene-Jun 2027';

    $page = signInToHiddenMenu('admin')
        // Cerrado: el grupo entero lo dice en su encabezado; la entrada suelta, con la insignia solo con el icono.
        ->assertSeeIn('[data-menu-group="validacion"] [data-menu-badge]', 'Oculto')
        ->assertVisible('a[data-label="Cierre de la carga"] [data-menu-badge]')
        ->assertScript('getComputedStyle(document.querySelector(\'a[data-label="Cierre de la carga"] [data-menu-badge] span\')).display', 'none')
        ->assertMissing('a[data-label="Horarios publicados"] [data-menu-badge]');

    // Al enfocar una entrada oculta sale su motivo a la derecha; Esc lo esconde sin tocar el menú.
    $page->script('document.querySelector(\'a[data-label="Cierre de la carga"]\').focus()');
    $page->assertScript(MENU_WHY_TIP.'?.text', $why)
        ->assertScript(MENU_WHY_TIP.'.box.left >= document.querySelector(\'a[data-label="Cierre de la carga"]\').getBoundingClientRect().right')
        ->keys('a[data-label="Cierre de la carga"]', 'Escape')
        ->assertScript(MENU_WHY_TIP, null)
        ->assertScript('document.body.dataset.menu', 'closed');

    // En la pantalla oculta, el aviso arriba del contenido.
    $page->click('a[data-label="Avance del cierre"]')
        ->assertPathIs('/staffing/closing-board')
        ->assertSeeIn('#nexus-content [role="note"]', 'Oculta para los demás. No hay carga en curso en Ene-Jun 2027. Tú la ves porque administras Nexus; en el menú de los demás no aparece.');

    // Abierto, la insignia lleva su texto y el motivo sale debajo; el primer Esc lo esconde y el segundo cierra.
    $page->click('@hare')
        ->assertScript('Alpine.$data(document.body).busy === false')
        ->assertSeeIn('a[data-label="Cierre de la carga"] [data-menu-badge]', 'Oculta')
        ->assertSeeIn('[data-menu-group="asignacion"] [data-menu-badge]', 'Oculto');

    $page->script('document.querySelector(\'a[data-label="Asignación docente"]\').focus()');
    $page->assertScript(MENU_WHY_TIP.'?.text', $why)
        ->assertScript(MENU_WHY_TIP.'.box.top >= document.querySelector(\'a[data-label="Asignación docente"]\').getBoundingClientRect().bottom')
        ->keys('a[data-label="Asignación docente"]', 'Escape')
        ->assertScript(MENU_WHY_TIP, null)
        ->assertScript('document.body.dataset.menu', 'open')
        ->keys('a[data-label="Asignación docente"]', 'Escape')
        ->assertScript('document.body.dataset.menu', 'closed');

    // El buscador deja lo que coincide con sus marcas: el grupo sigue oculto, por todas sus entradas, aunque solo
    // quede una, y esa sigue sin insignia porque la lleva el encabezado.
    $page->click('@hare')
        ->type('#nexus-menu-search', 'choques')
        ->assertSeeIn('[data-menu-group="horarios"] [data-menu-badge]', 'Oculto')
        ->assertVisible('a[data-label="Choques y avance"]')
        ->assertNotPresent('a[data-label="Choques y avance"] [data-menu-badge]')
        ->assertMissing('[data-menu-group="validacion"]')
        ->assertNoJavaScriptErrors();
});

it('en la hoja del celular, el admin lee el motivo bajo el grupo y bajo la entrada; el buscador lo esconde con ellos', function () {
    signInToHiddenMenu('admin', 390)
        ->click('@hare')
        ->assertVisible('[data-menu-group="validacion"] + [data-menu-note]')
        ->assertSeeIn('[data-menu-group="validacion"] + [data-menu-note]', 'No hay validación abierta en Ene-Jun 2027')
        ->assertSeeIn('a[data-label="Cierre de la carga"] + [data-menu-note]', 'No hay carga en curso en Ene-Jun 2027')
        ->assertSeeIn('a[data-label="Cierre de la carga"] [data-menu-badge]', 'Oculta')
        ->type('#nexus-menu-search', 'cierre de')
        ->assertVisible('a[data-label="Cierre de la carga"] + [data-menu-note]')
        ->assertMissing('[data-menu-group="validacion"] + [data-menu-note]')
        ->assertMissing('a[data-label="Avance del cierre"] + [data-menu-note]')
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth')
        ->assertNoJavaScriptErrors();
});

it('el coordinador no ve nada de la carga ni ninguna marca: de Académico, solo Horarios publicados', function () {
    signInToHiddenMenu('19110001')
        ->assertVisible('a[data-label="Horarios publicados"]')
        ->assertMissing('a[data-label="Propuesta de carrera"]')
        ->click('@hare')
        ->assertScript('[...document.querySelectorAll(\'[data-menu-area="academico"] a[data-menu-entry]\')].map((a) => a.dataset.label).join()', 'Horarios publicados')
        ->assertNotPresent('[data-menu-badge]')
        ->assertNotPresent('[data-why]')
        ->assertNoJavaScriptErrors();
});

it('el motivo flotante es de quien lo abrió: el cursor no cierra el del foco, el foco no cierra el del cursor y Esc solo se come el del foco', function () {
    $why = 'Oculta para los demás: No hay carga en curso en Ene-Jun 2027';
    $page = signInToHiddenMenu('admin')->click('@hare')->assertScript('Alpine.$data(document.body).busy === false');

    // Abierto por el foco: pasar el cursor por otra parte no lo cierra.
    $page->script('document.querySelector(\'a[data-label="Asignación docente"]\').focus()');
    $page->assertScript(MENU_WHY_TIP.'?.text', $why)
        ->hover('a[data-label="Campus"]')
        ->assertScript(MENU_WHY_TIP.'?.text', $why)
        ->hover('a[data-label="Cierre de la carga"]')
        ->assertScript('Alpine.$data(document.body).whyOwner', 'focus');

    // Abierto por el cursor: un foco que entra y sale de otra parte no lo cierra.
    $page->script('document.activeElement.blur()');
    $page->hover('a[data-label="Campus"]')
        ->assertScript(MENU_WHY_TIP, null)
        ->hover('a[data-label="Cierre de la carga"]')
        ->assertScript(MENU_WHY_TIP.'?.text', $why)
        ->assertScript('Alpine.$data(document.body).whyOwner', 'pointer');

    $page->script('(() => { const search = document.getElementById("nexus-menu-search"); search.focus(); search.blur(); })()');
    $page->assertScript(MENU_WHY_TIP.'?.text', $why)
        // Esc no se lo come: cierra el menú como siempre, y el motivo se va con él. Lo manda el buscador, que no
        // lleva motivo, sin mover el cursor.
        ->keys('#nexus-menu-search', 'Escape')
        ->assertScript('document.body.dataset.menu', 'closed')
        ->assertScript(MENU_WHY_TIP, null)
        ->assertNoJavaScriptErrors();
});

it('abierto, si el motivo no cabe debajo de su entrada, sale arriba', function () {
    $page = signInToHiddenMenu('admin')
        ->resize(1440, 420)
        ->click('@hare')
        ->assertScript('Alpine.$data(document.body).busy === false');

    // Enfocarla la deja a la vista en la orilla de abajo del menú abierto.
    $page->script('document.querySelector(\'a[data-label="Acuerdos"]\').focus()');
    $page->assertScript('(() => {
        const entry = document.querySelector(\'a[data-label="Acuerdos"]\').getBoundingClientRect();
        const tip = document.querySelector("[data-menu-why-tip]").getBoundingClientRect();

        return entry.bottom + 4 + tip.height > window.innerHeight - 8 && tip.bottom <= entry.top && tip.top >= 0;
    })()', true)
        ->assertNoJavaScriptErrors();
});
