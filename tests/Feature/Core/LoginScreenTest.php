<?php

use App\Modules\Core\Models\LoginNotice;
use App\Modules\Core\Models\LoginShortcut;

/*
| La pantalla de acceso G (tarea 1.17) con credencial (tarea 1.23): avisos del carrusel (LG-01), los logos en placas, la
| credencial con la ficha vacía y el formulario, y los atajos en su reverso (LG-02).
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

it('LG-02: con atajos activos muestra el botón y la lista en el reverso de la credencial, fuera del alcance del teclado', function () {
    LoginShortcut::factory()->create(['title' => 'Sitio del TecNM', 'url' => 'https://www.tecnm.mx']);

    $page = domOf($this->get('/login')->assertOk());
    $back = $page->querySelector('article[aria-labelledby="login-shortcuts-title"]');

    expect($page->querySelector('button[aria-controls="login-shortcuts-title"]'))->not->toBeNull()
        ->and($back)->not->toBeNull()
        ->and($back->hasAttribute('inert'))->toBeTrue()
        ->and(textOf($back))->toContain('Sitio del TecNM')
        ->and($back->querySelector('a[href="https://www.tecnm.mx"]'))->not->toBeNull();
});

it('LG-02: sin atajos activos la credencial no tiene reverso', function () {
    expect(domOf($this->get('/login')->assertOk())->querySelector('article[aria-labelledby="login-shortcuts-title"]'))->toBeNull();
});

it('muestra los logos en placas, y la credencial con la marca, la ficha vacía y el formulario', function () {
    $page = domOf($this->get('/login')->assertOk());
    $front = $page->querySelector('article[aria-labelledby="login-badge-title"]');

    expect($page->querySelectorAll('[data-login-plate] [role="img"]'))->toHaveCount(2)
        ->and($page->querySelector('[data-login-plate] [aria-label="Tecnológico Nacional de México"]'))->not->toBeNull()
        ->and($page->querySelector('[data-login-plate] [aria-label="Instituto Tecnológico de Ciudad Juárez"]'))->not->toBeNull()
        ->and($front)->not->toBeNull()
        ->and(textOf($front->querySelector('h1')))->toBe('Nexus')
        ->and(textOf($front->querySelector('dl')))->toBe('Nombre Puesto Usuario Periodo')
        ->and($front->querySelector('form[action="'.route('login.store').'"] input[name="username"]'))->not->toBeNull()
        ->and($front->querySelector('form input[name="password"]'))->not->toBeNull();
});
