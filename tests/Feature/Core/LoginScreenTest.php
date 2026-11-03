<?php

use App\Modules\Core\Models\LoginNotice;
use App\Modules\Core\Models\LoginShortcut;

/*
| La pantalla de acceso G (tarea 1.17): avisos del carrusel (LG-01) y botón de atajos (LG-02).
*/

it('LG-01: muestra los avisos vigentes y no los vencidos ni los inactivos', function () {
    $this->travelTo('2026-11-05 10:00');
    LoginNotice::factory()->create(['title' => 'Reinscripciones Ene–Jun 2027']);
    LoginNotice::factory()->create(['title' => 'Aviso vencido', 'ends_at' => '2026-11-01']);
    LoginNotice::factory()->create(['title' => 'Aviso apagado', 'is_active' => false]);

    $this->get('/login')
        ->assertOk()
        ->assertSee('Reinscripciones Ene–Jun 2027')
        ->assertDontSee('Aviso vencido')
        ->assertDontSee('Aviso apagado');
});

it('LG-01: sin avisos vigentes deja la foto del edificio sin carrusel', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('aria-label="Avisos"', false)
        ->assertDontSee('aria-roledescription="aviso"', false)
        ->assertDontSee('Aviso 1 de');
});

it('LG-02: sin atajos activos no muestra el botón de atajos', function () {
    LoginShortcut::factory()->create(['title' => 'Sitio apagado', 'is_active' => false]);

    $this->get('/login')
        ->assertOk()
        ->assertDontSee('Atajos')
        ->assertDontSee('Sitio apagado');
});

it('LG-02: con atajos activos muestra el botón y la lista', function () {
    LoginShortcut::factory()->create(['title' => 'Sitio del TecNM', 'url' => 'https://www.tecnm.mx']);

    $this->get('/login')
        ->assertOk()
        ->assertSee('Atajos')
        ->assertSee('Sitio del TecNM')
        ->assertSee('https://www.tecnm.mx', false);
});
