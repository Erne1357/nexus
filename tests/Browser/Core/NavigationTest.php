<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use Tests\Fixtures\Modules\DemoCatalog\DemoCatalogServiceProvider;

/*
| Layout en el navegador. DemoCatalog (tests/Fixtures/Modules) agrega "Saludo", para coordinadores,
| en Servicios escolares, y "Elementos", solo para el admin, en Recursos humanos, a "Inicio" de Core
| (Académico). Cerrado, el menú muestra el área de la pantalla; las demás se ven al abrirlo con la
| liebre. Los iconos de área no abren el menú: llevan a la última página que visitaste del área. El
| campus, el periodo y el tema están en la tarjeta de la credencial.
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
        ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])
        ->create(['username' => '19110001', 'name' => 'Carlos Coordinador']);
});

function signInFromBrowser(string $username)
{
    return visit('/login')
        ->fill('username', $username)
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->assertScript('window.Alpine?.$data(document.body).menuOpen === false');
}

it('navegación muestra solo lo permitido por rol', function () {
    $page = signInFromBrowser('19110001')
        ->assertSeeIn('[aria-label="Navegación principal"]', 'Inicio')
        ->assertMissing('a[data-label="Saludo"]')
        ->click('@hare')
        ->assertSeeIn('[aria-label="Navegación principal"]', 'Saludo')
        ->assertDontSeeIn('[aria-label="Navegación principal"]', 'Elementos')
        ->assertSee('Coordinador de carrera')
        ->keys('#nexus-menu-search', 'Escape')
        ->assertScript('document.body.dataset.menu', 'closed')
        ->assertNoJavaScriptErrors();

    $page->click('Carlos Coordinador')
        ->press('Cerrar sesión')
        ->assertPathIs('/login');

    signInFromBrowser('admin')
        ->assertSeeIn('[aria-label="Navegación principal"]', 'Inicio')
        ->click('@hare')
        ->assertSeeIn('[aria-label="Navegación principal"]', 'Saludo')
        ->assertSeeIn('[aria-label="Navegación principal"]', 'Elementos')
        ->assertSee('Administración')
        ->assertNoJavaScriptErrors();
});

it('el icono de otra área lleva a la última página que visitaste de ella, con el menú cerrado; el menú lo abre la liebre', function () {
    $page = signInFromBrowser('admin');

    $page->navigate('/campus?search=Campus')
        ->assertScript('window.Alpine?.$data(document.body).menuOpen === false')
        ->click('[data-menu-area="datos"] [data-menu-flip="icon"]')
        ->assertPathIs('/imports')
        ->assertScript('document.body.dataset.menu', 'closed')
        ->click('[data-menu-area="academico"] [data-menu-flip="icon"]')
        ->assertPathIs('/campus')
        ->assertQueryStringHas('search', 'Campus')
        ->assertScript('document.body.dataset.menu', 'closed')
        ->click('@hare')
        ->assertScript('document.body.dataset.menu', 'open')
        ->assertNoJavaScriptErrors();
});

it('el menú no brinca: lo que espera para moverse sale de su lugar inicial, y al cerrar todo va a la vez', function () {
    $page = signInFromBrowser('admin');

    // Las animaciones del menú y de la copia que se desvanece al cerrar. Una con retraso y sin fill
    // backwards pinta el elemento en su lugar final mientras espera y después brinca al inicial.
    $page->script(<<<'JS'
        (() => {
            window.menuMotion = () => {
                const ofMenu = document.getAnimations()
                    .filter((animation) => animation.effect?.target?.closest?.('#nexus-menu, [data-menu-ghost]'));

                return {
                    count: ofMenu.length,
                    jumps: ofMenu.filter((animation) => {
                        const { delay, fill } = animation.effect.getTiming();

                        return delay > 0 && fill !== 'backwards' && fill !== 'both';
                    }).length,
                    ghost: ofMenu.some((animation) => animation.effect.target.closest('[data-menu-ghost]') !== null),
                };
            };
        })()
    JS);

    $opening = $page->script('(() => { Alpine.$data(document.body).openMenu(); return menuMotion(); })()');

    $page->assertScript('Alpine.$data(document.body).busy === false');

    $closing = $page->script('(() => { Alpine.$data(document.body).closeMenu(); return { closedAtOnce: document.body.dataset.menu === "closed", ...menuMotion() }; })()');

    expect($opening)->count->toBeGreaterThan(0)->jumps->toBe(0)
        ->and($closing)->closedAtOnce->toBeTrue()->ghost->toBeTrue()->jumps->toBe(0);

    $page->assertScript('document.querySelector("[data-menu-ghost]") === null')
        ->assertScript('document.activeElement.id', 'nexus-hare')
        ->assertNoJavaScriptErrors();
});

it('cambia el campus activo desde la credencial y lo confirma con un toast', function () {
    $page = signInFromBrowser('admin')
        ->assertSelected('campus', '')
        ->click('@credential')
        ->select('campus', (string) $this->c1->id)
        ->waitForText('Campus I · Ene-Jun 2027')
        ->assertSee('Contexto actualizado')
        ->refresh()
        ->assertSelected('campus', (string) $this->c1->id)
        ->assertSeeIn('@credential', 'Administración · Campus I · Ene-Jun 2027')
        ->assertNoJavaScriptErrors();

    $page->assertScript('window.Alpine?.$data(document.body).menuOpen === false')
        ->click('@credential')
        ->select('campus', (string) $this->c2->id)
        ->waitForText('Campus II · Ene-Jun 2027')
        ->refresh()
        ->assertSelected('campus', (string) $this->c2->id);
});

it('el coordinador ve fijo su único campus', function () {
    signInFromBrowser('19110001')
        ->assertSeeIn('@credential', 'Coordinador de carrera · Campus II · Ene-Jun 2027')
        ->click('@credential')
        ->assertSeeIn('#nexus-credential', 'Campus II')
        ->assertNotPresent('select[name="campus"]');
});

it('cambia entre el tema claro y el oscuro desde la credencial', function () {
    $page = signInFromBrowser('admin')
        ->assertScript('document.documentElement.dataset.theme', 'nexus')
        ->click('@credential')
        ->assertVisible('@theme-toggle')
        ->radio('nexus-theme', 'nexus-dark')
        ->assertScript('document.documentElement.dataset.theme', 'nexus-dark');

    $page->refresh()
        ->assertScript('document.documentElement.dataset.theme', 'nexus-dark')
        ->assertScript('window.Alpine?.$data(document.body).menuOpen === false')
        ->click('@credential')
        ->assertRadioSelected('nexus-theme', 'nexus-dark')
        ->radio('nexus-theme', 'nexus')
        ->assertScript('document.documentElement.dataset.theme', 'nexus');
});
