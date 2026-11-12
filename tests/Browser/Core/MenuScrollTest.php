<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;

/*
| La columna cerrada del menú se desplaza dentro de sí misma: con una ventana baja, el admin (que ve
| todas las entradas del área) llega a la última sin que la página se mueva. Abrir el menú sigue igual.
*/

beforeEach(function () {
    registerDemoCatalog();

    Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    Term::factory()->current()->create(['name' => 'Ene-Jun 2027']);
    User::factory()->admin()->create(['username' => 'admin', 'name' => 'Ana Admin']);
});

it('columna cerrada del menú se desplaza y alcanza la última entrada en una ventana baja', function () {
    visit('/login')
        ->fill('username', 'admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->resize(1280, 260)
        ->assertScript('document.body.dataset.menu', 'closed')
        ->assertScript('(() => { const n = document.getElementById("nexus-menu"); return n.scrollHeight > n.clientHeight && getComputedStyle(n).overflowY === "auto"; })()', true)
        ->assertScript('(() => { const n = document.getElementById("nexus-menu"); n.scrollTop = n.scrollHeight; const e = [...n.querySelectorAll("[data-menu-entry]")].filter(a => a.offsetParent !== null).pop(); return e.getBoundingClientRect().bottom <= window.innerHeight; })()', true)
        ->click('@hare')
        ->assertScript('document.body.dataset.menu', 'open')
        ->assertScript('getComputedStyle(document.getElementById("nexus-menu")).overflowY', 'auto')
        ->assertNoJavaScriptErrors();
});
