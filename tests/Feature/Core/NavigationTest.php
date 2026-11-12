<?php

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\NavigationGroup;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\MenuEntry;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Navigation\RecentPages;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Students\Models\Student;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/*
| Navegación por rol. Cada módulo declara sus entradas en navigation() de su provider y Core las
| junta en un registro inmutable al arrancar; el menú las agrupa por área y, dentro de un área, por grupo
| (ADR-0015, MN-01). Inicio no es una entrada: el menú lo pinta aparte, para cualquier usuario activo.
| Una entrada puede depender del periodo activo (MN-02): quien no la puede ver sin estar disponible no la
| ve, y el admin la ve con su motivo. DemoCatalog (tests/Fixtures/Modules) declara "Saludo", para
| coordinadores (por Actor), en Servicios escolares, y "Elementos", por la ability viewAny de su policy
| (solo admin), en Recursos humanos.
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C2']);
});

function withDemoCatalogNavigation(): void
{
    // Al arrancar, Laravel indexa los nombres de las rutas y Core arma el registro; un módulo
    // registrado después obliga a hacer todo otra vez.
    registerDemoCatalog();
}

/**
 * Un registro armado a mano, para probar el agrupado sin depender de lo que declaren los módulos:
 * "Cargas" (Administración) la ve cualquiera y va primero; "Propuestas" (Académico) solo el coordinador (y
 * el admin); "Avisos" (Académico) cualquiera; "Plantilla" (Recursos humanos) quien pase viewAny de Campus.
 */
function handBuiltNavigation(): Navigation
{
    return new Navigation([
        new NavigationItem('Cargas', 'home', 'o-table-cells', NavigationArea::Administracion),
        new NavigationItem('Propuestas', 'home', 'o-document-text', NavigationArea::Academico,
            visible: static fn (Actor $context): bool => $context->allowsAny(AcademicLoadPermission::ProposalCreate)),
        new NavigationItem('Avisos', 'home', 'o-bell', NavigationArea::Academico),
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

    $areas = [];

    foreach (($navigation ?? app(Navigation::class))->visibleByArea(app(Actor::class)) as $area) {
        foreach ($area['groups'] as $group) {
            foreach ($group['entries'] as $entry) {
                $areas[$area['area']->value][] = $entry->item->label;
            }
        }
    }

    return $areas;
}

/**
 * Las etiquetas visibles por grupo de un área («—» para las que no van en grupo), en el orden del menú.
 *
 * @return array<string, list<string>>
 */
function navigationGroupsFor(User $user, NavigationArea $area, ?Navigation $navigation = null): array
{
    test()->actingAs($user);

    $found = collect(($navigation ?? app(Navigation::class))->visibleByArea(app(Actor::class)))
        ->firstWhere('area', $area);

    $groups = [];

    foreach ($found['groups'] ?? [] as $group) {
        $groups[$group['group']?->value ?? '—'] = array_map(fn (MenuEntry $entry): string => $entry->item->label, $group['entries']);
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
        app(Navigation::class)->visibleTo(app(Actor::class)),
    );
}

/**
 * Una entrada de prueba: va a Inicio para no depender de rutas de módulos.
 */
function testEntry(string $label, NavigationArea $area = NavigationArea::Academico, ?NavigationGroup $group = null, int $order = 0, ?Closure $whyUnavailable = null): NavigationItem
{
    return new NavigationItem($label, 'home', 'o-home', $area, group: $group, order: $order, whyUnavailable: $whyUnavailable);
}

it('Inicio no es una entrada del registro: el menú lo pinta aparte', function () {
    expect(collect(app(Navigation::class)->items())->pluck('route'))->not->toContain('home')
        ->and(app(Navigation::class)->forRoute('home'))->toBeNull();
});

it('toda entrada declara su área como cuarto argumento', function () {
    expect(fn () => new NavigationItem('Inicio', 'home', 'o-home'))->toThrow(ArgumentCountError::class)
        ->and(fn () => new NavigationItem('Inicio', 'home', 'o-home', 'academico'))->toThrow(TypeError::class)
        ->and(new NavigationItem('Inicio', 'home', 'o-home', NavigationArea::Administracion))->area->toBe(NavigationArea::Administracion);
});

it('MN-01: una entrada no puede ir en un grupo de otra área', function () {
    expect(fn () => testEntry('Campus', NavigationArea::Academico, NavigationGroup::Institucion))
        ->toThrow(InvalidArgumentException::class, 'El grupo Institución es del área Administración')
        ->and(testEntry('Campus', NavigationArea::Administracion, NavigationGroup::Institucion))->group->toBe(NavigationGroup::Institucion);
});

describe('agrupado por área', function () {
    it('pone las áreas en el orden del enum y, dentro de cada una, las entradas en el orden en que se declaran', function () {
        $admin = User::factory()->admin()->create();

        expect(navigationAreasFor($admin, handBuiltNavigation()))->toBe([
            'academico' => ['Propuestas', 'Avisos'],
            'recursos_humanos' => ['Plantilla'],
            'administracion' => ['Cargas'],
        ]);
    });

    it('deja fuera las entradas que el rol no ve y las áreas que se quedan vacías', function () {
        $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => careerKey(20)])->create();
        $docente = User::factory()->withRole(Role::Docente, ['campus_id' => $this->campus->id])->create();

        // Plantilla, la única de Recursos humanos, pide la ability viewAny de Campus: ninguno de los dos la pasa.
        expect(navigationAreasFor($coordinador, handBuiltNavigation()))->toBe([
            'academico' => ['Propuestas', 'Avisos'],
            'administracion' => ['Cargas'],
        ])
            ->and(navigationAreasFor($docente, handBuiltNavigation()))->toBe([
                'academico' => ['Avisos'],
                'administracion' => ['Cargas'],
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
            ->academico->toContain('Propuesta de carrera', 'Horarios')
            ->servicios_escolares->toContain('Saludo')
            ->recursos_humanos->toContain('Elementos')
            ->administracion->toContain('Campus', 'Periodos', 'Importaciones')
            ->and(navigationAreasFor($coordinador))
            ->academico->toContain('Propuesta de carrera')
            ->servicios_escolares->toContain('Saludo')
            ->not->toHaveKey('administracion');
    });
});

describe('MN-01: grupos dentro de un área', function () {
    it('pone primero las entradas sin grupo y luego los grupos en el orden del enum', function () {
        $navigation = new Navigation([
            testEntry('Cierre', group: NavigationGroup::CierrePublicacion),
            testEntry('Suelta'),
            testEntry('Propuesta', group: NavigationGroup::Propuesta),
            testEntry('Validar', group: NavigationGroup::Validacion),
        ]);

        expect(navigationGroupsFor(User::factory()->admin()->create(), NavigationArea::Academico, $navigation))->toBe([
            '—' => ['Suelta'],
            'validacion' => ['Validar'],
            'propuesta' => ['Propuesta'],
            'cierre_publicacion' => ['Cierre'],
        ]);
    });

    it('dentro de un grupo ordena por `order` y, a igual orden, por el registro', function () {
        $navigation = new Navigation([
            testEntry('Publicados', group: NavigationGroup::CierrePublicacion, order: 1),
            testEntry('Cierre', group: NavigationGroup::CierrePublicacion),
            testEntry('Avance', group: NavigationGroup::CierrePublicacion),
        ]);

        expect(navigationGroupsFor(User::factory()->admin()->create(), NavigationArea::Academico, $navigation))
            ->toBe(['cierre_publicacion' => ['Cierre', 'Avance', 'Publicados']]);
    });

    it('no muestra un grupo sin entradas visibles para el rol', function () {
        $navigation = new Navigation([
            testEntry('Validar', group: NavigationGroup::Validacion),
            new NavigationItem('Propuestas', 'home', 'o-home', NavigationArea::Academico, group: NavigationGroup::Propuesta,
                visible: static fn (): bool => false),
        ]);

        expect(navigationGroupsFor(User::factory()->admin()->create(), NavigationArea::Academico, $navigation))
            ->toBe(['validacion' => ['Validar']]);
    });

    it('lleva encabezados solo si el área muestra dos grupos o más y ocho entradas o más', function (array $sizes, bool $headings) {
        $items = [];
        $groups = [NavigationGroup::Validacion, NavigationGroup::Propuesta, NavigationGroup::Horarios];

        foreach ($sizes as $index => $size) {
            foreach (range(1, $size) as $number) {
                $items[] = testEntry("E{$index}-{$number}", group: $groups[$index]);
            }
        }

        $this->actingAs(User::factory()->admin()->create());
        $area = (new Navigation($items))->visibleByArea(app(Actor::class))[0];

        expect($area['headings'])->toBe($headings);
    })->with([
        'dos grupos y ocho entradas' => [[5, 3], true],
        'tres grupos y nueve entradas' => [[3, 3, 3], true],
        'dos grupos y siete entradas: lista plana' => [[4, 3], false],
        'un grupo con diez entradas: lista plana' => [[10], false],
    ]);

    it('las entradas sin grupo no cuentan como grupo para los encabezados', function () {
        $items = [testEntry('Suelta'), ...array_map(fn (int $number): NavigationItem => testEntry("V{$number}", group: NavigationGroup::Validacion), range(1, 8))];

        $this->actingAs(User::factory()->admin()->create());

        expect((new Navigation($items))->visibleByArea(app(Actor::class))[0]['headings'])->toBeFalse();
    });
});

describe('MN-02: disponibilidad en el periodo activo', function () {
    beforeEach(function () {
        $this->navigation = new Navigation([
            testEntry('Siempre'),
            testEntry('Sin validación', group: NavigationGroup::Validacion, whyUnavailable: static fn (): string => 'No hay validación abierta'),
            testEntry('Disponible', group: NavigationGroup::Propuesta, whyUnavailable: static fn (): ?string => null),
        ]);
    });

    it('quien no es admin no ve lo que no está disponible en el periodo', function () {
        $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => careerKey(20)])->create();

        expect(navigationAreasFor($coordinador, $this->navigation))->toBe(['academico' => ['Siempre', 'Disponible']]);
    });

    it('el admin lo ve, marcado con su motivo; lo disponible va sin motivo', function () {
        $this->actingAs(User::factory()->admin()->create());

        $entries = collect($this->navigation->visibleByArea(app(Actor::class))[0]['groups'])
            ->flatMap(fn (array $group): array => $group['entries'])
            ->mapWithKeys(fn (MenuEntry $entry): array => [$entry->item->label => $entry->hiddenReason]);

        expect($entries->all())->toBe(['Siempre' => null, 'Sin validación' => 'No hay validación abierta', 'Disponible' => null]);
    });

    it('visibleTo da lo mismo que el menú, aplanado y en su orden', function () {
        $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => careerKey(20)])->create();
        $this->actingAs($coordinador);

        expect(array_map(fn (NavigationItem $item): string => $item->label, $this->navigation->visibleTo(app(Actor::class))))
            ->toBe(['Siempre', 'Disponible']);
    });

    it('evalúa cada condición una vez por menú, aunque la compartan varias entradas', function () {
        $calls = 0;
        $condition = function () use (&$calls): ?string {
            $calls++;

            return null;
        };

        $navigation = new Navigation([
            testEntry('A', group: NavigationGroup::Horarios, whyUnavailable: $condition),
            testEntry('B', group: NavigationGroup::Horarios, whyUnavailable: $condition),
            testEntry('C', group: NavigationGroup::Asignacion, whyUnavailable: $condition),
        ]);

        $this->actingAs(User::factory()->admin()->create());
        $navigation->visibleByArea(app(Actor::class));

        expect($calls)->toBe(1);
    });

    it('no evalúa la condición de una entrada que el rol no puede ver', function () {
        $navigation = new Navigation([
            new NavigationItem('Oculta', 'home', 'o-home', NavigationArea::Academico,
                visible: static fn (): bool => false,
                whyUnavailable: static fn (): never => throw new LogicException('No debió evaluarse')),
        ]);

        $this->actingAs(User::factory()->admin()->create());

        expect($navigation->visibleByArea(app(Actor::class)))->toBe([]);
    });
});

describe('entrada de una ruta', function () {
    it('da la entrada declarada para una ruta, la vea o no el usuario', function () {
        $item = app(Navigation::class)->forRoute('core.campuses');

        expect($item)
            ->label->toBe('Campus')
            ->area->toBe(NavigationArea::Administracion)
            ->group->toBe(NavigationGroup::Institucion)
            ->and(app(Navigation::class)->forRoute('imports.wizard'))
            ->label->toBe('Importaciones')
            ->area->toBe(NavigationArea::Administracion)
            ->group->toBe(NavigationGroup::Datos);
    });

    it('da la primera entrada si dos declaran la misma ruta', function () {
        expect(handBuiltNavigation()->forRoute('home')->label)->toBe('Cargas');
    });

    it('a una ruta hija que la entrada declara le da esa entrada: la ficha de un alumno es de Alumnos', function () {
        expect(app(Navigation::class)->forRoute('students.show'))
            ->label->toBe('Alumnos')
            ->area->toBe(NavigationArea::ServiciosEscolares);
    });

    it('no adivina por el prefijo: una ruta que ninguna entrada declara no es de ninguna', function () {
        expect(app(Navigation::class)->forRoute('core.users'))->toBeNull()
            ->and(app(Navigation::class)->forRoute('students.import'))->toBeNull();
    });

    it('no da ninguna para una ruta que no está en el menú', function () {
        expect(app(Navigation::class)->forRoute('login'))->toBeNull()
            ->and(app(Navigation::class)->forRoute('no.existe'))->toBeNull();
    });
});

it('MN-01: el admin ve Académico por etapas y Administración por propósito', function () {
    $admin = User::factory()->admin()->create();

    expect(navigationGroupsFor($admin, NavigationArea::Academico))->toBe([
        'validacion' => ['Validar catálogos', 'Avance de validación'],
        'propuesta' => ['Propuesta de carrera', 'Bandeja del departamento'],
        'horarios' => ['Horarios', 'Choques y avance', 'Acuerdos'],
        'asignacion' => ['Asignación docente', 'Carga por docente', 'Solicitudes de cambio'],
        'cierre_publicacion' => ['Cierre de la carga', 'Avance del cierre', 'Horarios publicados'],
    ])
        ->and(navigationGroupsFor($admin, NavigationArea::Administracion))->toBe([
            'institucion' => ['Campus', 'Periodos', 'Organigrama', 'Personas y puestos'],
            'catalogos' => ['Departamentos', 'Carreras', 'Materias', 'Planes de estudio', 'Edificios', 'Salones'],
            'datos' => ['Importaciones'],
        ])
        ->and(navigationGroupsFor($admin, NavigationArea::ServiciosEscolares))->toBe(['—' => ['Alumnos']])
        ->and(navigationGroupsFor($admin, NavigationArea::RecursosHumanos))->toBe(['—' => ['Docentes']]);
});

it('filtra por rol: admin, coordinador y docente ven menús distintos', function () {
    withDemoCatalogNavigation();

    $admin = User::factory()->admin()->create();
    $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => careerKey(20)])->create();
    $docente = User::factory()->withRole(Role::Docente, ['campus_id' => $this->campus->id])->create();

    // Presencia y ausencia, no listas exactas: cada módulo nuevo agrega entradas sin romper esta prueba.
    expect(navigationLabelsFor($admin))->toContain('Campus', 'Periodos', 'Saludo', 'Elementos')
        ->and(navigationLabelsFor($coordinador))->toContain('Saludo')->not->toContain('Campus')->not->toContain('Periodos')->not->toContain('Elementos')
        ->and(navigationLabelsFor($docente))->not->toContain('Campus')->not->toContain('Periodos')->not->toContain('Saludo')->not->toContain('Elementos');
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

it('a quien no tiene un rol vigente no le muestra ninguna entrada (solo el Inicio del menú)', function () {
    withDemoCatalogNavigation();

    expect(navigationLabelsFor(User::factory()->create()))->toBe([]);
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

        expect(menuAreasIn($page))->toBe(['Académico' => ['Inicio', 'Propuesta de carrera', 'Horarios publicados', 'Horarios', 'Choques y avance', 'Solicitudes de cambio'], 'Servicios escolares' => ['Alumnos', 'Saludo']])
            ->and($page->querySelector('a[data-menu-entry][href="'.route('demo-catalog.greeting').'"]')?->hasAttribute('wire:navigate'))->toBeTrue()
            ->and($page->querySelector('a[href="'.route('demo-catalog.ping').'"]'))->toBeNull();
    });

    it('muestra al admin todas las entradas', function () {
        withDemoCatalogNavigation();
        $this->actingAs(User::factory()->admin()->create());

        expect(menuAreasIn(domOf(Livewire::test('core::components.navigation'))))
            ->{'Académico'}->toContain('Inicio', 'Campus', 'Periodos')
            ->{'Servicios escolares'}->toContain('Alumnos', 'Saludo')
            ->{'Recursos humanos'}->toContain('Elementos')
            ->{'Datos'}->toContain('Importaciones');
    });

    it('muestra al docente Inicio y su «Mi espacio»', function () {
        withDemoCatalogNavigation();
        $docente = User::factory()->withRole(Role::Docente, ['campus_id' => $this->campus->id])->create();
        Teacher::factory()->create(['user_id' => $docente->id]);
        $this->actingAs($docente);

        expect(menuAreasIn(domOf(Livewire::test('core::components.navigation'))))->toBe(['Mi espacio' => ['Mi carga', 'Oferta'], 'Académico' => ['Inicio']]);
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
            ->toBe(['Académico', 'Recursos humanos', 'Datos']);
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

    it('en una pantalla hija, marca la entrada de su listado como ubicación y no como la página', function () {
        $this->actingAs(User::factory()->admin()->create());

        $page = domOf(Livewire::test('core::components.navigation', ['route' => 'students.show']));

        expect($page->querySelectorAll('#nexus-menu [aria-current="page"]'))->toHaveCount(0)
            ->and(textOf($page->querySelector('#nexus-menu [aria-current="true"]')))->toBe('Alumnos')
            // Se ve igual que la entrada activa: fondo, negritas y el punto de «estás aquí».
            ->and($page->querySelector('#nexus-menu [aria-current="true"]')->hasAttribute('data-active'))->toBeTrue();
    });

    it('el área actual es la de la pantalla; si la pantalla no está en el menú, la primera', function (string $route, string $area) {
        $this->actingAs(User::factory()->admin()->create());

        $current = domOf(Livewire::test('core::components.navigation', ['route' => $route]))
            ->querySelectorAll('#nexus-menu [data-menu-area][data-current]');

        expect($current)->toHaveCount(1)
            ->and($current->item(0)->getAttribute('aria-label'))->toBe($area);
    })->with([
        'Campus' => ['core.campuses', 'Académico'],
        'Periodos' => ['core.terms', 'Académico'],
        'Importaciones' => ['imports.wizard', 'Datos'],
        'Alumnos' => ['students.index', 'Servicios escolares'],
        'la ficha de un alumno, hija de Alumnos' => ['students.show', 'Servicios escolares'],
        'fuera del menú' => ['user-password.edit', 'Académico'],
    ]);

    it('si el área de la pantalla no es visible para el usuario, el área actual es la primera que ve', function () {
        $this->actingAs(User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => careerKey(20)])->create());

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

/**
 * El icono de un área en el menú de una página: el enlace que viaja entre la franja y su columna.
 */
function areaIconIn(HTMLDocument $page, string $area): ?Element
{
    return $page->querySelector('#nexus-menu [data-menu-area="'.$area.'"] [data-menu-flip="icon"]');
}

/*
| Los iconos de área (DESIGN.md, "Navigation"): un clic lleva a la última página que visitaste del área,
| con su consulta, o a su primera entrada si no has visitado ninguna; el del área actual, a su primera
| entrada. No abren el menú: eso es de la liebre, Ctrl K y el buscador de la banda.
*/
describe('iconos de área', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->admin()->create());
    });

    it('cada icono es un enlace con el nombre de su área, que navega y no abre el menú', function () {
        $page = domOf($this->get(route('home'))->assertOk());
        $icon = areaIconIn($page, 'datos');

        expect($icon?->localName)->toBe('a')
            ->and($icon->getAttribute('aria-label'))->toBe('Datos')
            ->and($icon->getAttribute('data-tip'))->toBe('Datos')
            ->and($icon->hasAttribute('wire:navigate'))->toBeTrue()
            ->and($icon->hasAttribute('aria-hidden'))->toBeFalse()
            ->and($page->saveHtml($icon))->not->toContain('openMenu');
    });

    it('sin visitas, el icono de un área lleva a su primera entrada visible', function () {
        $page = domOf($this->get(route('home'))->assertOk());

        expect(areaIconIn($page, 'servicios_escolares')->getAttribute('href'))->toBe(route('students.index'))
            ->and(areaIconIn($page, 'datos')->getAttribute('href'))->toBe(route('imports.wizard'));
    });

    it('desde otra área, el icono lleva a la última página que visitaste del área, con su consulta', function () {
        $this->get(route('core.campuses', ['search' => 'norte']))->assertOk();
        $this->get(route('academic.subjects', ['search' => 'cal']))->assertOk();

        $page = domOf($this->get(route('imports.wizard'))->assertOk());

        expect(areaIconIn($page, 'academico')->getAttribute('href'))->toBe(route('academic.subjects', ['search' => 'cal'], false));
    });

    it('guarda la página con una sola barra al inicio: una petición a //campus no deja un enlace a otro host', function () {
        $this->get(route('home'))->assertOk();
        app(RecentPages::class)->remember('core.campuses', '//campus?search=norte');

        expect(app(RecentPages::class)->last(NavigationArea::Academico))->toBe('/campus?search=norte');
    });

    it('una pantalla hija cuenta como página de su área: la ficha de un alumno, con su pestaña', function () {
        $uri = route('students.show', ['student' => Student::factory()->create(), 'tab' => 'kardex'], false);
        $this->get($uri)->assertOk();

        $page = domOf($this->get(route('home'))->assertOk());

        expect(areaIconIn($page, 'servicios_escolares')->getAttribute('href'))->toBe($uri);
    });

    it('el icono del área actual lleva a su primera entrada, aunque estés en otra página del área', function () {
        $page = domOf($this->get(route('core.campuses', ['search' => 'norte']))->assertOk());

        expect(areaIconIn($page, 'academico')->getAttribute('href'))->toBe(route('home'));
    });

    it('no recuerda una página cuya entrada el usuario no ve', function () {
        app()->instance(Navigation::class, new Navigation([
            new NavigationItem('Inicio', 'home', 'o-home', NavigationArea::Academico),
            new NavigationItem('Oculta', 'core.campuses', 'o-eye-slash', NavigationArea::Datos,
                visible: static fn (): bool => false),
            new NavigationItem('Importaciones', 'imports.wizard', 'o-arrow-up-tray', NavigationArea::Datos),
        ]));

        $this->get(route('core.campuses'))->assertOk();

        $page = domOf($this->get(route('home'))->assertOk());

        expect(areaIconIn($page, 'datos')->getAttribute('href'))->toBe(route('imports.wizard'));
    });

    it('al cambiar el campus o el periodo activo, olvida las páginas que visitaste', function () {
        $this->get(route('core.campuses', ['search' => 'norte']))->assertOk();

        Livewire::test('core::components.context-switcher')
            ->set('campusId', $this->campus->id)
            ->call('apply')
            ->assertHasNoErrors();

        $page = domOf($this->get(route('imports.wizard'))->assertOk());

        expect(areaIconIn($page, 'academico')->getAttribute('href'))->toBe(route('home'));
    });
});
