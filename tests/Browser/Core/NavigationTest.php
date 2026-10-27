<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use Tests\Fixtures\Modules\DemoCatalog\DemoCatalogServiceProvider;

/*
| Layout en el navegador. DemoCatalog (tests/Fixtures/Modules) agrega "Saludo", para coordinadores,
| y "Elementos", solo para el admin, a la entrada "Inicio" de Core.
*/

beforeEach(function () {
    // Como al arrancar: indexar los nombres de las rutas del módulo y rearmar el registro.
    $this->app->register(DemoCatalogServiceProvider::class);
    $this->app['router']->getRoutes()->refreshNameLookups();
    $this->app->forgetInstance(Navigation::class);

    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    Term::factory()->current()->create(['name' => 'Ene-Jun 2027']);

    User::factory()->admin()->create(['username' => 'admin', 'name' => 'Ana Admin']);
    User::factory()
        ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => fakeKey(20)])
        ->create(['username' => '19110001', 'name' => 'Carlos Coordinador']);
});

function signInFromBrowser(string $username)
{
    return visit('/login')
        ->fill('username', $username)
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/');
}

it('navegación muestra solo lo permitido por rol', function () {
    $page = signInFromBrowser('19110001')
        ->assertSeeIn('[aria-label="Navegación principal"]', 'Inicio')
        ->assertSeeIn('[aria-label="Navegación principal"]', 'Saludo')
        ->assertDontSeeIn('[aria-label="Navegación principal"]', 'Elementos')
        ->assertSee('Coordinador de carrera')
        ->assertNoJavaScriptErrors();

    $page->click('Carlos Coordinador')
        ->press('Cerrar sesión')
        ->assertPathIs('/login');

    signInFromBrowser('admin')
        ->assertSeeIn('[aria-label="Navegación principal"]', 'Inicio')
        ->assertSeeIn('[aria-label="Navegación principal"]', 'Saludo')
        ->assertSeeIn('[aria-label="Navegación principal"]', 'Elementos')
        ->assertSee('Administración')
        ->assertNoJavaScriptErrors();
});

it('cambia el campus activo desde la topbar y lo confirma con un toast', function () {
    $page = signInFromBrowser('admin')
        ->assertSelected('campus', '')
        ->select('campus', (string) $this->c1->id)
        ->waitForText('Campus I · Ene-Jun 2027')
        ->assertSee('Contexto actualizado')
        ->refresh()
        ->assertSelected('campus', (string) $this->c1->id)
        ->assertNoJavaScriptErrors();

    $page->select('campus', (string) $this->c2->id)
        ->waitForText('Campus II · Ene-Jun 2027')
        ->refresh()
        ->assertSelected('campus', (string) $this->c2->id);
});

it('el coordinador ve fijo su único campus', function () {
    signInFromBrowser('19110001')
        ->assertSee('Campus II')
        ->assertNotPresent('select[name="campus"]');
});

it('cambia entre el tema claro y el oscuro', function () {
    $page = signInFromBrowser('admin')
        ->assertScript('document.documentElement.dataset.theme', 'nexus')
        ->click('@theme-toggle')
        ->assertScript('document.documentElement.dataset.theme', 'nexus-dark');

    $page->refresh()
        ->assertScript('document.documentElement.dataset.theme', 'nexus-dark')
        ->click('@theme-toggle')
        ->assertScript('document.documentElement.dataset.theme', 'nexus');
});
