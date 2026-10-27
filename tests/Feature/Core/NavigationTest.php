<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Queries\ScopedContext;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Fixtures\Modules\DemoCatalog\DemoCatalogServiceProvider;

/*
| Navegación por rol. Cada módulo declara sus entradas en navigation() de su provider y Core las
| junta en un registro inmutable al arrancar. DemoCatalog (tests/Fixtures/Modules) declara
| "Saludo", para coordinadores (por ScopedContext), y "Elementos", por la ability viewAny de su
| policy (solo admin).
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C2']);
});

function withDemoCatalogNavigation(): void
{
    app()->register(DemoCatalogServiceProvider::class);

    // Al arrancar, Laravel indexa los nombres de las rutas y Core arma el registro; un módulo
    // registrado después obliga a hacer las dos cosas otra vez.
    app('router')->getRoutes()->refreshNameLookups();
    app()->forgetInstance(Navigation::class);
}

/**
 * @return list<string>
 */
function navigationLabelsFor(?User $user): array
{
    if ($user !== null) {
        test()->actingAs($user);
    }

    return array_map(
        fn (NavigationItem $item): string => $item->label,
        app(Navigation::class)->visibleTo(app(ScopedContext::class)),
    );
}

it('Core declara Inicio, visible para cualquier usuario con sesión', function () {
    $docente = User::factory()->withRole(Role::Docente, ['campus_id' => $this->campus->id])->create();

    expect(navigationLabelsFor($docente))->toBe(['Inicio'])
        ->and(app(Navigation::class)->items()[0])
        ->route->toBe('home')
        ->icon->toBe('o-home');
});

it('filtra por rol: admin, coordinador y docente ven menús distintos', function () {
    withDemoCatalogNavigation();

    $admin = User::factory()->admin()->create();
    $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => 20])->create();
    $docente = User::factory()->withRole(Role::Docente, ['campus_id' => $this->campus->id])->create();

    expect(navigationLabelsFor($admin))->toBe(['Inicio', 'Saludo', 'Elementos'])
        ->and(navigationLabelsFor($coordinador))->toBe(['Inicio', 'Saludo'])
        ->and(navigationLabelsFor($docente))->toBe(['Inicio']);
});

it('decide por la ability de Gate con la policy del módulo', function () {
    withDemoCatalogNavigation();

    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => 10])->create();

    expect(navigationLabelsFor($jefe))->not->toContain('Elementos')
        ->and(navigationLabelsFor(User::factory()->admin()->create()))->toContain('Elementos');
});

it('no muestra ninguna entrada sin sesión ni a un usuario inactivo', function () {
    expect(navigationLabelsFor(null))->toBe([])
        ->and(navigationLabelsFor(User::factory()->admin()->create(['is_active' => false])))->toBe([]);
});

it('a quien no tiene un rol vigente le muestra solo Inicio', function () {
    withDemoCatalogNavigation();

    expect(navigationLabelsFor(User::factory()->create()))->toBe(['Inicio']);
});

it('toda entrada declarada apunta a una ruta registrada', function () {
    withDemoCatalogNavigation();

    $missing = collect(app(Navigation::class)->items())
        ->reject(fn (NavigationItem $item): bool => Route::has($item->route))
        ->map(fn (NavigationItem $item): string => "{$item->label} → {$item->route}")
        ->all();

    expect($missing)->toBeEmpty(implode(PHP_EOL, $missing));
});

it('se arma una vez para toda la aplicación, no por petición', function () {
    $navigation = app(Navigation::class);

    app()->forgetScopedInstances();

    expect(app(Navigation::class))->toBe($navigation);
});

describe('barra lateral', function () {
    it('muestra al coordinador sus entradas con enlace e icono, y no las del admin', function () {
        withDemoCatalogNavigation();
        $this->actingAs(User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => 20])->create());

        Livewire::test('core::components.navigation')
            ->assertSee('Inicio')
            ->assertSee('Saludo')
            ->assertSee(route('demo-catalog.greeting'))
            ->assertDontSee('Elementos')
            ->assertDontSee(route('demo-catalog.ping'));
    });

    it('muestra al admin todas las entradas', function () {
        withDemoCatalogNavigation();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test('core::components.navigation')
            ->assertSee('Inicio')
            ->assertSee('Saludo')
            ->assertSee('Elementos')
            ->assertSee(route('demo-catalog.ping'));
    });

    it('muestra al docente solo Inicio', function () {
        withDemoCatalogNavigation();
        $this->actingAs(User::factory()->withRole(Role::Docente, ['campus_id' => $this->campus->id])->create());

        Livewire::test('core::components.navigation')
            ->assertSee('Inicio')
            ->assertDontSee('Saludo')
            ->assertDontSee('Elementos');
    });
});
