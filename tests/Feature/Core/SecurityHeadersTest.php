<?php

use App\Modules\Core\Models\User;

/*
| Endurecimiento (tarea 4.8): toda respuesta de la app lleva las cabeceras de seguridad, también la pantalla de acceso,
| que se ve sin sesión, y las respuestas de error. HSTS no lo pone la app: lo pone el proxy que termina TLS (Caddy).
*/

it('pone las cabeceras de seguridad en la pantalla de acceso', function () {
    $response = $this->get('/login');

    $response->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");
});

it('las pone también con sesión y en un 404', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get('/')->assertHeader('X-Frame-Options', 'DENY');
    $this->get('/no-existe')->assertNotFound()->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('no pone HSTS, que es del proxy', function () {
    $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');
});
