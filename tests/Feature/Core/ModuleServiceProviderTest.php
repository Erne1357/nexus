<?php

use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Fixtures\Modules\Bare\BareServiceProvider;
use Tests\Fixtures\Modules\DemoCatalog\DemoCatalogServiceProvider;
use Tests\Fixtures\Modules\DemoCatalog\Models\Item;
use Tests\Fixtures\Modules\DemoCatalog\Policies\ItemPolicy;
use Tests\Fixtures\Modules\DemoCatalog\Policies\NotificationPolicy;

/*
| DemoCatalog y Bare son módulos de ejemplo en tests/Fixtures/Modules: se registran
| solo aquí. El alias del módulo es su nombre en kebab-case (DemoCatalog → demo-catalog).
*/

beforeEach(function () {
    $this->app->register(DemoCatalogServiceProvider::class);
});

it('registra las rutas de Routes/web.php con el middleware web', function () {
    $this->get('/demo-catalog/ping')->assertOk()->assertSee('pong');

    $route = app('router')->getRoutes()->match(Request::create('/demo-catalog/ping'));

    expect($route->middleware())->toContain('web');
});

it('registra las migraciones de Database/Migrations', function () {
    $migrator = app('migrator');

    expect($migrator->paths())
        ->toContain(base_path('tests/Fixtures/Modules/DemoCatalog/Database/Migrations'))
        ->and($migrator->getMigrationFiles($migrator->paths()))
        ->toHaveKey('2026_01_01_000000_create_demo_catalog_items_table');
});

it('registra las vistas de Views con el alias del módulo', function () {
    expect(view('demo-catalog::hello', ['name' => 'Ana'])->render())->toContain('Hola, Ana');
});

it('resuelve y renderiza páginas y componentes MFC de Livewire con el alias del módulo', function () {
    Livewire::test('demo-catalog::pages.greeting')->assertSee('Saludo desde una página del módulo');
    Livewire::test('demo-catalog::components.badge')->assertSee('Insignia de un componente del módulo');
});

it('sirve una página MFC del módulo desde sus rutas', function () {
    $this->get('/demo-catalog/greeting')->assertOk()->assertSee('Saludo desde una página del módulo');
});

it('registra las traducciones de Lang con el alias del módulo', function () {
    expect(__('demo-catalog::messages.greeting'))->toBe('Hola desde el catálogo de ejemplo');
});

it('resuelve por convención Policies/<Modelo>Policy para Models/<Modelo>', function () {
    expect(Gate::getPolicyFor(Item::class))->toBeInstanceOf(ItemPolicy::class);
});

it('registra las policies declaradas en $policies que la convención no alcanza', function () {
    expect(Gate::getPolicyFor(DatabaseNotification::class))->toBeInstanceOf(NotificationPolicy::class);
});

it('arranca un módulo sin carpetas sin registrar rutas, vistas ni traducciones', function () {
    $routesBefore = app('router')->getRoutes()->count();

    $this->app->register(BareServiceProvider::class);

    expect(app('router')->getRoutes()->count())->toBe($routesBefore)
        ->and(app('view')->getFinder()->getHints())->not->toHaveKey('bare')
        ->and(app('translator')->getLoader()->namespaces())->not->toHaveKey('bare');
});

it('permite crear con make:livewire el primer componente MFC de un módulo sin carpeta Livewire', function () {
    $livewire = base_path('tests/Fixtures/Modules/Bare/Livewire');
    File::deleteDirectory($livewire);

    try {
        $this->app->register(BareServiceProvider::class);

        $this->artisan('make:livewire', ['name' => 'bare::pages.first', '--no-interaction' => true])
            ->assertSuccessful();

        expect("{$livewire}/pages/first/first.php")->toBeFile()
            ->and("{$livewire}/pages/first/first.blade.php")->toBeFile();

        Livewire::test('bare::pages.first')->assertOk();
    } finally {
        File::deleteDirectory($livewire);
    }
});
