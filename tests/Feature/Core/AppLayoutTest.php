<?php

use App\Modules\Core\Models\User;
use Tests\Fixtures\Modules\DemoCatalog\DemoCatalogServiceProvider;

/*
| Layout de la aplicación (resources/views/layouts/app.blade.php): barra lateral con la navegación,
| topbar con el selector de contexto, el tema y el menú de usuario, y los toasts de MaryUI.
*/

it('muestra el menú de usuario con su nombre, el cambio de contraseña y el cierre de sesión', function () {
    $this->actingAs(User::factory()->admin()->create(['name' => 'Ana López']))
        ->get('/')
        ->assertOk()
        ->assertSee('Ana López')
        ->assertSee('Cambiar contraseña')
        ->assertSee('href="'.route('user-password.edit').'"', false)
        ->assertSee('Cerrar sesión')
        ->assertSee('method="POST" action="'.route('logout').'"', false);
});

it('pone en la topbar el selector de campus y periodo', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/')
        ->assertSeeLivewire('core::components.context-switcher')
        ->assertSeeLivewire('core::components.navigation');
});

it('deja listos los toasts de MaryUI para cualquier página', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/')
        ->assertSee('@mary-toast.window', false);
});

it('cambia entre el tema claro nexus y el oscuro nexus-dark', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/')
        ->assertSee("'nexus-dark'", false)
        ->assertSee("'nexus'", false)
        ->assertSee('Cambiar entre tema claro y oscuro');
});

it('un invitado no ve el layout aunque la página no pida sesión', function () {
    $this->app->register(DemoCatalogServiceProvider::class);

    $this->get('/demo-catalog/greeting')
        ->assertOk()
        ->assertSee('Saludo desde una página del módulo')
        ->assertDontSee('Navegación principal')
        ->assertDontSee('Cerrar sesión')
        ->assertDontSeeLivewire('core::components.context-switcher');
});

it('la barra lateral de MaryUI solo guarda true o false en la sesión', function (string $sent, string $stored) {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/mary/toogle-sidebar?collapsed='.urlencode($sent))
        ->assertOk();

    expect(session('mary-sidebar-collapsed'))->toBe($stored);

    $this->get('/')->assertSee("collapsed: {$stored},", false);
})->with([
    'true' => ['true', 'true'],
    'false' => ['false', 'false'],
    'código en lugar de un booleano' => ['alert(document.cookie)', 'false'],
]);
