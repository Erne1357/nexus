<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleScope;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Livewire\Livewire;
use Tests\Fixtures\Modules\DemoCatalog\DemoCatalogServiceProvider;

/*
| Layout de la aplicación (resources/views/layouts/app.blade.php, DESIGN.md "Estructura"): la banda
| (core::components.band) con la liebre, la ruta, el buscador y la credencial, que se abre como
| tarjeta; el menú que se transforma (core::components.navigation); el contenido y los toasts de
| MaryUI.
*/

beforeEach(function () {
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    Term::factory()->current()->create(['name' => 'Ene-Jun 2027']);
});

describe('banda', function () {
    it('pone la liebre como el botón del menú completo, cerrado al cargar la página', function () {
        $page = domOf($this->actingAs(User::factory()->admin()->create())->get('/')->assertOk());
        $hare = $page->querySelector('[data-testid="hare"]');

        expect($hare?->tagName)->toBe('BUTTON')
            ->and($hare->getAttribute('aria-label'))->toBe('Abrir el menú completo')
            ->and($hare->getAttribute('aria-expanded'))->toBe('false')
            ->and($hare->getAttribute('aria-controls'))->toBe('nexus-menu')
            ->and($page->getElementById('nexus-menu')?->getAttribute('aria-label'))->toBe('Navegación principal')
            ->and($page->body->getAttribute('data-menu'))->toBe('closed');
    });

    it('lleva «Nexus» a la página de inicio y no tiene otro logo', function () {
        $page = domOf($this->actingAs(User::factory()->admin()->create())->get('/'));
        $home = $page->querySelector('header a[href="'.route('home').'"]');

        expect(textOf($home))->toBe('Nexus')
            ->and($home->hasAttribute('wire:navigate'))->toBeTrue()
            ->and($page->querySelectorAll('header img'))->toHaveCount(0);
    });

    it('muestra en la ruta el área de la pantalla y su título', function () {
        $page = domOf($this->actingAs(User::factory()->admin()->create())->get(route('core.campuses'))->assertOk());
        $crumb = $page->querySelector('nav[aria-label="Ruta"]');

        expect(textOf($crumb))->toBe('Académico Campus')
            ->and(textOf($crumb->querySelector('[aria-current="page"]')))->toBe('Campus');
    });

    it('sin entrada en el menú, la ruta muestra solo el título de la pantalla', function () {
        $this->actingAs(User::factory()->admin()->create());

        $crumb = domOf(Livewire::test('core::components.band', ['title' => 'Pantalla suelta', 'route' => 'no.existe']))
            ->querySelector('nav[aria-label="Ruta"]');

        expect(textOf($crumb))->toBe('Pantalla suelta');
    });

    it('ofrece «Buscar o ir a…» con su atajo, que abre el menú', function () {
        $page = domOf($this->actingAs(User::factory()->admin()->create())->get('/'));
        $search = $page->querySelector('[data-testid="menu-search-trigger"]');

        expect(textOf($search))->toBe('Buscar o ir a… Ctrl K')
            ->and($search->getAttribute('aria-controls'))->toBe('nexus-menu')
            ->and($page->querySelector('button[aria-label="Buscar"][aria-controls="nexus-menu"]'))->not->toBeNull();
    });

    it('muestra en la credencial el nombre, las iniciales, el rol, el campus y el periodo activos', function () {
        $coordinador = User::factory()
            ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => fakeKey(20)])
            ->create(['name' => 'Carla Cota Ruiz']);

        $credential = domOf($this->actingAs($coordinador)->get('/'))->querySelector('[data-testid="credential"]');

        expect(textOf($credential))->toBe('CC Carla Cota Ruiz Coordinador de carrera · Campus II · Ene-Jun 2027')
            ->and($credential->getAttribute('aria-expanded'))->toBe('false')
            ->and($credential->getAttribute('aria-controls'))->toBe('nexus-credential');
    });

    it('al admin sin campus elegido le muestra su rol y el periodo', function () {
        Campus::factory()->create(['code' => 'C1']);

        $credential = domOf($this->actingAs(User::factory()->admin()->create(['name' => 'Ana López']))->get('/'))
            ->querySelector('[data-testid="credential"]');

        expect(textOf($credential))->toBe('AL Ana López Administración · Ene-Jun 2027');
    });

    it('a quien ejerce varios roles le muestra el primero, y a quien no tiene uno vigente, ninguno', function () {
        $jefe = User::factory()
            ->withRole(Role::JefeDepartamento, ['campus_id' => $this->c2->id, 'department_id' => fakeKey(10)])
            ->withRole(Role::Docente, ['campus_id' => $this->c2->id])
            ->create(['name' => 'Juan Pérez']);
        $vencido = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => fakeKey(20)])->create(['name' => 'Eva Luna']);
        RoleScope::where('user_id', $vencido->id)->update(['valid_to' => today()->subDay()]);

        expect(textOf(domOf($this->actingAs($jefe)->get('/'))->querySelector('[data-testid="credential"]')))
            ->toBe('JP Juan Pérez Jefe de departamento académico · Campus II · Ene-Jun 2027')
            ->and(textOf(domOf($this->actingAs($vencido)->get('/'))->querySelector('[data-testid="credential"]')))
            ->toBe('EL Eva Luna Ene-Jun 2027');
    });

    it('escapa el nombre del usuario y el del campus', function () {
        $this->c2->update(['name' => '<script>alert(1)</script>']);
        $coordinador = User::factory()
            ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => fakeKey(20)])
            ->create(['name' => '<img src=x onerror=alert(2)>']);

        $this->actingAs($coordinador)->get('/')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x onerror=alert(2)>', false)
            ->assertSee('&lt;img src=x onerror=alert(2)&gt;', false);
    });
});

describe('tarjeta de la credencial', function () {
    it('lleva el encabezado con el nombre, el rol y el usuario', function () {
        $page = domOf($this->actingAs(User::factory()->admin()->create(['name' => 'Ana López', 'username' => 'alopez']))->get('/'));
        $card = $page->getElementById('nexus-credential');

        expect($card?->getAttribute('role'))->toBe('dialog')
            ->and($card->getAttribute('aria-label'))->toBe('Tu credencial')
            ->and($card->getAttribute('data-open'))->toBe('false')
            ->and(textOf($card->querySelector('[data-testid="credential-header"]')))->toBe('AL Ana López Administración · alopez');
    });

    it('trae el selector de campus y periodo, el tema, el cambio de contraseña y el cierre de sesión', function () {
        Campus::factory()->create(['code' => 'C1']);

        $response = $this->actingAs(User::factory()->admin()->create())->get('/')
            ->assertSeeLivewire('core::components.context-switcher');
        $card = domOf($response)->getElementById('nexus-credential');
        $logout = $card->querySelector('form[action="'.route('logout').'"]');

        expect($card->querySelector('select[name="campus"]'))->not->toBeNull()
            ->and($card->querySelector('select[name="term"]'))->not->toBeNull()
            ->and($card->querySelector('[data-testid="theme-toggle"]'))->not->toBeNull()
            ->and(textOf($card->querySelector('a[href="'.route('user-password.edit').'"]')))->toBe('Cambiar contraseña')
            ->and(strtoupper($logout->getAttribute('method')))->toBe('POST')
            ->and(textOf($logout->querySelector('button[type="submit"]')))->toBe('Cerrar sesión');
    });

    it('elige el tema claro nexus o el oscuro nexus-dark con el mecanismo de MaryUI', function () {
        $response = $this->actingAs(User::factory()->admin()->create())->get('/')
            ->assertSee("'nexus-dark'", false)
            ->assertSee("'nexus'", false)
            ->assertSee('mary-theme', false);
        $toggle = domOf($response)->querySelector('[data-testid="theme-toggle"]');

        expect($toggle->querySelector('input[type="radio"][value="nexus"]')?->getAttribute('aria-label'))->toBe('Claro')
            ->and($toggle->querySelector('input[type="radio"][value="nexus-dark"]')?->getAttribute('aria-label'))->toBe('Oscuro');
    });
});

it('pone el menú y el contenido de la página después de la banda', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/')
        ->assertSeeLivewire('core::components.band')
        ->assertSeeLivewire('core::components.navigation')
        ->assertSeeInOrder(['data-testid="hare"', 'id="nexus-menu"', '<main', 'Hola,'], false);
});

it('deja listos los toasts de MaryUI para cualquier página', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/')
        ->assertSee('@mary-toast.window', false);
});

it('un invitado no ve el layout aunque la página no pida sesión', function () {
    $this->app->register(DemoCatalogServiceProvider::class);

    $this->get('/demo-catalog/greeting')
        ->assertOk()
        ->assertSee('Saludo desde una página del módulo')
        ->assertDontSee('Navegación principal')
        ->assertDontSee('Abrir el menú completo')
        ->assertDontSee('Cerrar sesión')
        ->assertDontSeeLivewire('core::components.band')
        ->assertDontSeeLivewire('core::components.context-switcher');
});

/*
| El shell ya no usa <x-main> de MaryUI, pero la ruta con que MaryUI guarda el estado de su barra
| lateral sigue registrada: la de Core solo guarda 'true' o 'false', y NormalizeSidebarState reescribe
| cualquier otro valor, por si una pantalla vuelve a usar <x-main>.
*/
it('la ruta de la barra lateral de MaryUI solo guarda true o false en la sesión', function (string $sent, string $stored) {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/mary/toogle-sidebar?collapsed='.urlencode($sent))
        ->assertOk();

    expect(session('mary-sidebar-collapsed'))->toBe($stored);
})->with([
    'true' => ['true', 'true'],
    'false' => ['false', 'false'],
    'código en lugar de un booleano' => ['alert(document.cookie)', 'false'],
]);

it('reescribe a false un estado de la barra lateral que no sea true o false antes de pintar la página', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->withSession(['mary-sidebar-collapsed' => 'alert(1)'])
        ->get('/')
        ->assertOk()
        ->assertDontSee('alert(1)', false);

    expect(session('mary-sidebar-collapsed'))->toBe('false');
});

it('conserva el estado true de la barra lateral', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->withSession(['mary-sidebar-collapsed' => 'true'])
        ->get('/');

    expect(session('mary-sidebar-collapsed'))->toBe('true');
});
