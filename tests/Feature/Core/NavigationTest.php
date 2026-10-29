<?php

use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Queries\ScopedContext;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Fixtures\Modules\DemoCatalog\DemoCatalogServiceProvider;

/*
| Navegación por rol. Cada módulo declara sus entradas en navigation() de su provider y Core las
| junta en un registro inmutable al arrancar; el menú las agrupa por área (ADR-0015). DemoCatalog
| (tests/Fixtures/Modules) declara "Saludo", para coordinadores (por ScopedContext), en Servicios
| escolares, y "Elementos", por la ability viewAny de su policy (solo admin), en Recursos humanos.
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
 * Un registro armado a mano, para probar el agrupado sin depender de lo que declaren los módulos:
 * "Cargas" (Datos) la ve cualquiera y va primero; "Propuestas" (Académico) solo el coordinador (y el
 * admin); "Inicio" (Académico) cualquiera; "Plantilla" (Recursos humanos) quien pase viewAny de Campus.
 */
function handBuiltNavigation(): Navigation
{
    return new Navigation([
        new NavigationItem('Cargas', 'home', 'o-table-cells', NavigationArea::Datos),
        new NavigationItem('Propuestas', 'home', 'o-document-text', NavigationArea::Academico,
            visible: static fn (ScopedContext $context): bool => $context->has(Role::Coordinador)),
        new NavigationItem('Inicio', 'home', 'o-home', NavigationArea::Academico),
        new NavigationItem('Plantilla', 'core.campuses', 'o-users', NavigationArea::RecursosHumanos,
            ability: 'viewAny', arguments: [Campus::class]),
    ]);
}

/**
 * Las etiquetas visibles por área, con el valor del área como llave y en el orden del menú.
 *
 * @return array<string, list<string>>
 */
function navigationAreasFor(?User $user, ?Navigation $navigation = null): array
{
    if ($user !== null) {
        test()->actingAs($user);
    }

    $groups = [];

    foreach (($navigation ?? app(Navigation::class))->visibleByArea(app(ScopedContext::class)) as $group) {
        $groups[$group['area']->value] = array_map(fn (NavigationItem $item): string => $item->label, $group['items']);
    }

    return $groups;
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

    expect(navigationLabelsFor($docente))->toContain('Inicio')->not->toContain('Campus')
        ->and(app(Navigation::class)->items()[0])
        ->route->toBe('home')
        ->icon->toBe('o-home')
        ->area->toBe(NavigationArea::Academico);
});

it('toda entrada declara su área como cuarto argumento', function () {
    expect(fn () => new NavigationItem('Inicio', 'home', 'o-home'))->toThrow(ArgumentCountError::class)
        ->and(fn () => new NavigationItem('Inicio', 'home', 'o-home', 'academico'))->toThrow(TypeError::class)
        ->and(new NavigationItem('Inicio', 'home', 'o-home', NavigationArea::Datos))->area->toBe(NavigationArea::Datos);
});

describe('agrupado por área', function () {
    it('pone las áreas en el orden del enum y, dentro de cada una, las entradas en el orden en que se declaran', function () {
        $admin = User::factory()->admin()->create();

        expect(navigationAreasFor($admin, handBuiltNavigation()))->toBe([
            'academico' => ['Propuestas', 'Inicio'],
            'recursos_humanos' => ['Plantilla'],
            'datos' => ['Cargas'],
        ]);
    });

    it('deja fuera las entradas que el rol no ve y las áreas que se quedan vacías', function () {
        $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => careerKey(20)])->create();
        $docente = User::factory()->withRole(Role::Docente, ['campus_id' => $this->campus->id])->create();

        // Plantilla, la única de Recursos humanos, pide la ability viewAny de Campus: ninguno de los dos la pasa.
        expect(navigationAreasFor($coordinador, handBuiltNavigation()))->toBe([
            'academico' => ['Propuestas', 'Inicio'],
            'datos' => ['Cargas'],
        ])
            ->and(navigationAreasFor($docente, handBuiltNavigation()))->toBe([
                'academico' => ['Inicio'],
                'datos' => ['Cargas'],
            ]);
    });

    it('no arma ningún grupo sin sesión ni para un usuario inactivo', function () {
        expect(navigationAreasFor(null, handBuiltNavigation()))->toBe([])
            ->and(navigationAreasFor(User::factory()->admin()->create(['is_active' => false]), handBuiltNavigation()))->toBe([]);
    });

    it('agrupa las entradas que declaran los módulos', function () {
        withDemoCatalogNavigation();

        $admin = User::factory()->admin()->create();
        $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => careerKey(20)])->create();

        // Presencia y ausencia, no listas exactas, como arriba.
        expect(navigationAreasFor($admin))
            ->academico->toContain('Inicio', 'Campus')
            ->servicios_escolares->toContain('Saludo')
            ->recursos_humanos->toContain('Elementos')
            ->datos->toContain('Importaciones')
            ->and(navigationAreasFor($coordinador))
            ->academico->toContain('Inicio')->not->toContain('Campus')
            ->servicios_escolares->toContain('Saludo');
    });
});

describe('entrada de una ruta', function () {
    it('da la entrada declarada para una ruta, la vea o no el usuario', function () {
        $item = app(Navigation::class)->forRoute('core.campuses');

        expect($item)
            ->label->toBe('Campus')
            ->area->toBe(NavigationArea::Academico)
            ->and(app(Navigation::class)->forRoute('imports.wizard'))
            ->label->toBe('Importaciones')
            ->area->toBe(NavigationArea::Datos);
    });

    it('da la primera entrada si dos declaran la misma ruta', function () {
        expect(handBuiltNavigation()->forRoute('home')->label)->toBe('Cargas');
    });

    it('no da ninguna para una ruta que no está en el menú', function () {
        expect(app(Navigation::class)->forRoute('login'))->toBeNull()
            ->and(app(Navigation::class)->forRoute('no.existe'))->toBeNull();
    });
});

it('filtra por rol: admin, coordinador y docente ven menús distintos', function () {
    withDemoCatalogNavigation();

    $admin = User::factory()->admin()->create();
    $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => careerKey(20)])->create();
    $docente = User::factory()->withRole(Role::Docente, ['campus_id' => $this->campus->id])->create();

    // Presencia y ausencia, no listas exactas: cada módulo nuevo agrega entradas sin romper esta prueba.
    expect(navigationLabelsFor($admin))->toContain('Inicio', 'Campus', 'Saludo', 'Elementos')
        ->and(navigationLabelsFor($coordinador))->toContain('Inicio', 'Saludo')->not->toContain('Campus')->not->toContain('Elementos')
        ->and(navigationLabelsFor($docente))->toContain('Inicio')->not->toContain('Campus')->not->toContain('Saludo')->not->toContain('Elementos');
});

it('decide por la ability de Gate con la policy del módulo', function () {
    withDemoCatalogNavigation();

    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => departmentKey(10)])->create();

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

/**
 * Las áreas del menú de un componente o una página, con el texto de sus entradas, en orden.
 *
 * @return array<string, list<string>>
 */
function menuAreasIn(HTMLDocument $page): array
{
    $areas = [];

    foreach ($page->querySelectorAll('#nexus-menu [data-menu-area]') as $group) {
        foreach ($group->querySelectorAll('a[data-menu-entry]') as $entry) {
            $areas[$group->getAttribute('aria-label')][] = textOf($entry);
        }
    }

    return $areas;
}

describe('menú', function () {
    it('muestra al coordinador sus entradas con su enlace, y no las del admin', function () {
        withDemoCatalogNavigation();
        $this->actingAs(User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => careerKey(20)])->create());

        $page = domOf(Livewire::test('core::components.navigation'));

        expect(menuAreasIn($page))->toBe(['Académico' => ['Inicio'], 'Servicios escolares' => ['Saludo']])
            ->and($page->querySelector('a[data-menu-entry][href="'.route('demo-catalog.greeting').'"]')?->hasAttribute('wire:navigate'))->toBeTrue()
            ->and($page->querySelector('a[href="'.route('demo-catalog.ping').'"]'))->toBeNull();
    });

    it('muestra al admin todas las entradas', function () {
        withDemoCatalogNavigation();
        $this->actingAs(User::factory()->admin()->create());

        expect(menuAreasIn(domOf(Livewire::test('core::components.navigation'))))
            ->{'Académico'}->toContain('Inicio', 'Campus')
            ->{'Servicios escolares'}->toContain('Saludo')
            ->{'Recursos humanos'}->toContain('Elementos')
            ->{'Datos'}->toContain('Importaciones');
    });

    it('muestra al docente solo Inicio', function () {
        withDemoCatalogNavigation();
        $this->actingAs(User::factory()->withRole(Role::Docente, ['campus_id' => $this->campus->id])->create());

        expect(menuAreasIn(domOf(Livewire::test('core::components.navigation'))))->toBe(['Académico' => ['Inicio']]);
    });

    it('en la página, el coordinador no ve las entradas de Campus ni de Importaciones', function () {
        $this->actingAs(User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => careerKey(20)])->create());

        $menu = domOf($this->get('/')->assertOk())->getElementById('nexus-menu');

        expect(textOf($menu))->toContain('Inicio')->not->toContain('Campus')->not->toContain('Importaciones')
            ->and($menu->querySelector('a[href="'.route('core.campuses').'"]'))->toBeNull()
            ->and($menu->querySelector('a[href="'.route('imports.wizard').'"]'))->toBeNull();
    });

    it('agrupa las entradas bajo el nombre de su área, en el orden del menú', function () {
        app()->instance(Navigation::class, handBuiltNavigation());
        $this->actingAs(User::factory()->admin()->create());

        $page = domOf(Livewire::test('core::components.navigation'));

        expect(menuAreasIn($page))->toBe([
            'Académico' => ['Propuestas', 'Inicio'],
            'Recursos humanos' => ['Plantilla'],
            'Datos' => ['Cargas'],
        ])
            ->and(array_map(fn (Element $name): string => textOf($name), iterator_to_array($page->querySelectorAll('[data-menu-area] [data-menu-flip="name"]'))))
            ->toBe(['Académico', 'Recursos humanos', 'Datos'])
            ->and($page->querySelector('[data-menu-area] [data-menu-flip="icon"]')?->getAttribute('aria-hidden'))->toBe('true');
    });

    it('no muestra el área de las entradas que el rol no ve', function () {
        app()->instance(Navigation::class, handBuiltNavigation());
        $this->actingAs(User::factory()->withRole(Role::Docente, ['campus_id' => $this->campus->id])->create());

        expect(menuAreasIn(domOf(Livewire::test('core::components.navigation'))))->toBe([
            'Académico' => ['Inicio'],
            'Datos' => ['Cargas'],
        ]);
    });

    it('marca la entrada de la pantalla actual con aria-current y solo a ella', function () {
        $this->actingAs(User::factory()->admin()->create());

        $current = domOf(Livewire::test('core::components.navigation', ['route' => 'core.campuses']))
            ->querySelectorAll('#nexus-menu [aria-current="page"]');

        expect($current)->toHaveCount(1)
            ->and(textOf($current->item(0)))->toBe('Campus');
    });

    it('en la página, marca la entrada de la ruta que se pidió', function () {
        $this->actingAs(User::factory()->admin()->create());

        $current = domOf($this->get(route('imports.wizard'))->assertOk())->querySelectorAll('#nexus-menu [aria-current="page"]');

        expect($current)->toHaveCount(1)
            ->and(textOf($current->item(0)))->toBe('Importaciones');
    });

    it('el área actual es la de la pantalla; si la pantalla no está en el menú, la primera', function (string $route, string $area) {
        $this->actingAs(User::factory()->admin()->create());

        $current = domOf(Livewire::test('core::components.navigation', ['route' => $route]))
            ->querySelectorAll('#nexus-menu [data-menu-area][data-current]');

        expect($current)->toHaveCount(1)
            ->and($current->item(0)->getAttribute('aria-label'))->toBe($area);
    })->with([
        'Campus' => ['core.campuses', 'Académico'],
        'Importaciones' => ['imports.wizard', 'Datos'],
        'fuera del menú' => ['user-password.edit', 'Académico'],
    ]);

    it('si el área de la pantalla no es visible para el usuario, el área actual es la primera que ve', function () {
        $this->actingAs(User::factory()->withRole(Role::Docente, ['campus_id' => $this->campus->id])->create());

        $current = domOf(Livewire::test('core::components.navigation', ['route' => 'imports.wizard']))
            ->querySelectorAll('#nexus-menu [data-menu-area][data-current]');

        expect($current)->toHaveCount(1)
            ->and($current->item(0)->getAttribute('aria-label'))->toBe('Académico')
            ->and(domOf(Livewire::test('core::components.navigation', ['route' => 'imports.wizard']))->querySelector('[aria-current="page"]'))->toBeNull();
    });

    it('trae el buscador y el aviso de «Sin resultados», oculto mientras no se busca', function () {
        $this->actingAs(User::factory()->admin()->create());

        $page = domOf(Livewire::test('core::components.navigation'));
        $empty = $page->querySelector('#nexus-menu [data-menu-empty]');

        expect($page->querySelector('#nexus-menu input[type="search"]')?->getAttribute('aria-label'))->toBe('Buscar en el menú')
            ->and(textOf($empty))->toBe('Sin resultados')
            ->and($empty->hasAttribute('hidden'))->toBeTrue();
    });

    it('acomoda las áreas en cinco columnas y, en tableta, en tres, con las demás debajo de la más larga', function () {
        app()->instance(Navigation::class, new Navigation([
            new NavigationItem('A1', 'home', 'o-home', NavigationArea::Academico),
            new NavigationItem('A2', 'home', 'o-home', NavigationArea::Academico),
            new NavigationItem('S1', 'home', 'o-home', NavigationArea::ServiciosEscolares),
            new NavigationItem('S2', 'home', 'o-home', NavigationArea::ServiciosEscolares),
            new NavigationItem('S3', 'home', 'o-home', NavigationArea::ServiciosEscolares),
            new NavigationItem('H1', 'home', 'o-home', NavigationArea::RecursosHumanos),
            new NavigationItem('D1', 'home', 'o-home', NavigationArea::Datos),
        ]));
        $this->actingAs(User::factory()->admin()->create());

        $page = domOf(Livewire::test('core::components.navigation', ['route' => 'login']));
        $styleOf = fn (string $label): string => $page->querySelector('a[data-label="'.$label.'"]')->getAttribute('style');

        // Datos es la cuarta área visible: cuarta columna en escritorio y, en tableta, la primera,
        // debajo de la más larga de las tres de arriba (Servicios escolares, tres entradas): 3 + 3 + 2.
        expect($styleOf('A2'))->toBe('--c: 1; --i: 2; --col-t: 1; --base-t: 3')
            ->and($styleOf('S3'))->toBe('--c: 2; --i: 3; --col-t: 2; --base-t: 3')
            ->and($styleOf('H1'))->toBe('--c: 3; --i: 1; --col-t: 3; --base-t: 3')
            ->and($styleOf('D1'))->toBe('--c: 4; --i: 1; --col-t: 1; --base-t: 8');
    });
});
