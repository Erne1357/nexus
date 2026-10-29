<?php

use App\Modules\Core\Enums\NavigationArea;
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
        $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => fakeKey(20)])->create();
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
        $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => fakeKey(20)])->create();

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
    $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => fakeKey(20)])->create();
    $docente = User::factory()->withRole(Role::Docente, ['campus_id' => $this->campus->id])->create();

    // Presencia y ausencia, no listas exactas: cada módulo nuevo agrega entradas sin romper esta prueba.
    expect(navigationLabelsFor($admin))->toContain('Inicio', 'Campus', 'Saludo', 'Elementos')
        ->and(navigationLabelsFor($coordinador))->toContain('Inicio', 'Saludo')->not->toContain('Campus')->not->toContain('Elementos')
        ->and(navigationLabelsFor($docente))->toContain('Inicio')->not->toContain('Campus')->not->toContain('Saludo')->not->toContain('Elementos');
});

it('decide por la ability de Gate con la policy del módulo', function () {
    withDemoCatalogNavigation();

    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => fakeKey(10)])->create();

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
        $this->actingAs(User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => fakeKey(20)])->create());

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

    it('agrupa las entradas bajo el nombre de su área, en el orden del menú', function () {
        app()->instance(Navigation::class, handBuiltNavigation());
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test('core::components.navigation')
            ->assertSeeInOrder(['Académico', 'Propuestas', 'Inicio', 'Recursos humanos', 'Plantilla', 'Datos', 'Cargas'])
            ->assertSeeHtml('aria-label="Académico"')
            ->assertSeeHtml('aria-label="Datos"')
            ->assertDontSee('Servicios escolares')
            ->assertDontSee('Recursos financieros');
    });

    it('no muestra el área de las entradas que el rol no ve', function () {
        app()->instance(Navigation::class, handBuiltNavigation());
        $this->actingAs(User::factory()->withRole(Role::Docente, ['campus_id' => $this->campus->id])->create());

        Livewire::test('core::components.navigation')
            ->assertSeeInOrder(['Académico', 'Inicio', 'Datos', 'Cargas'])
            ->assertDontSee('Propuestas')
            ->assertDontSee('Recursos humanos')
            ->assertDontSee('Plantilla');
    });
});
