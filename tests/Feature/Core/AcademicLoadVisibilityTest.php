<?php

use App\Modules\Core\Actions\MarkAllNotificationsRead;
use App\Modules\Core\Actions\UpdateSetting;
use App\Modules\Core\Authorization\AcademicLoadVisibility;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\MailLog;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\MenuEntry;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Notifications\AcademicLoadNotice;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Core\Queries\PageTrailQuery;
use App\Modules\Core\Settings\SettingRegistry;
use App\Modules\Core\Settings\SettingType;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Staffing\Notifications\ChangeAlternativeMovesCareerGroup;
use App\Modules\Staffing\Notifications\ChangeRequestMovedTeacherGroups;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| MN-05 (tarea 1.24.6): todo lo de la carga va en el área «Carga académica» del menú, y el admin la puede ocultar a los demás
| con un interruptor en «Parámetros» (`core.academic_load_visible`, visible por omisión). Oculta, sale del menú, del
| buscador, de la banda y de la campana, y sus rutas (la lista explícita AcademicLoadVisibility::ROUTES) responden 403 si
| piden sesión y 404 si son públicas, también a una acción de Livewire. El admin la sigue viendo, marcada «Oculta». Inicio y
| el mapa (`home`, `map.*`) nunca se bloquean. No se borra ni se detiene nada.
*/

final class HiddenLoadNotice extends NexusNotification
{
    public function __construct(private readonly string $title, private readonly ?string $route) {}

    public function message(object $notifiable): NotificationMessage
    {
        return new NotificationMessage(title: $this->title, body: 'Detalle', icon: 'o-bell', route: $this->route);
    }
}

/** Un aviso de la carga que sale por la campana y por correo, sin cola. */
final class HiddenLoadMailNotice extends NexusNotification implements AcademicLoadNotice
{
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function message(object $notifiable): NotificationMessage
    {
        return new NotificationMessage(title: 'Cambió tu carga', body: 'Detalle', icon: 'o-bell', route: 'scheduling.editor');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Cambió tu carga')->action('Ver el horario', route('scheduling.editor'));
    }
}

/** Un aviso al docente que lleva a «Mi carga» (siempre visible): sale por la campana y por correo aunque la carga esté oculta. */
final class HiddenMyLoadMailNotice extends NexusNotification
{
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function message(object $notifiable): NotificationMessage
    {
        return new NotificationMessage(title: 'Cambió el horario de tus grupos', body: 'Detalle', icon: 'o-bell', route: 'staffing.my-load');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Cambió el horario de tus grupos')->action('Ver mi carga', route('staffing.my-load'));
    }
}

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    $this->admin = User::factory()->admin()->create();
    $this->coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => careerKey(20)])->create();
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => departmentKey(10)])->create();
    $this->docente = User::factory()->withRole(Role::Docente, ['campus_id' => $this->campus->id])->create();
    $this->subdireccion = User::factory()->withRole(Role::Subdireccion)->create();
});

/**
 * El admin oculta (o muestra) la carga en «Parámetros», con la Action de la pantalla.
 */
function setAcademicLoadVisible(bool $visible): void
{
    app(UpdateSetting::class)->handle(app(SettingRegistry::class)->get(AcademicLoadVisibility::SETTING), $visible);
}

/**
 * La dirección de una ruta de la lista, con valores de relleno en sus parámetros.
 */
function loadVisibilityUrlOf(string $name): string
{
    $parameters = array_fill_keys(Route::getRoutes()->getByName($name)->parameterNames(), 'x');

    return route($name, array_merge($parameters, array_intersect_key(['periodo' => '20271', 'modalidad' => 'pres'], $parameters)), false);
}

/**
 * Si la ruta pide sesión (va detrás de `auth`).
 */
function loadVisibilityNeedsLogin(string $name): bool
{
    return in_array('auth', Route::getRoutes()->getByName($name)->gatherMiddleware(), true);
}

/**
 * Las áreas del menú del usuario, con las etiquetas de sus entradas.
 *
 * @return array<string, list<string>>
 */
function loadVisibilityMenuOf(User $user): array
{
    test()->actingAs($user);
    app(Actor::class)->refresh();

    $areas = [];

    foreach (app(Navigation::class)->visibleByArea(app(Actor::class)) as $area) {
        foreach ($area['groups'] as $group) {
            foreach ($group['entries'] as $entry) {
                $areas[$area['area']->value][] = $entry->item->label;
            }
        }
    }

    return $areas;
}

/**
 * El snapshot del componente de la página que pintó la respuesta (el que se llama como `$component`).
 */
function loadVisibilitySnapshotIn(TestResponse $response, string $component): string
{
    preg_match_all('/wire:snapshot="([^"]+)"/', (string) $response->getContent(), $matches);

    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5);

        if ((json_decode($snapshot, true)['memo']['name'] ?? null) === $component) {
            return $snapshot;
        }
    }

    throw new RuntimeException("La página no pintó {$component}.");
}

/**
 * Una acción de Livewire (`$refresh`) sobre el componente del snapshot, por el endpoint de verdad: así corren los
 * middleware persistentes con la ruta de la página.
 */
function loadVisibilityRefresh(string $snapshot): TestResponse
{
    return test()->withHeaders(['X-Livewire' => 'true'])->postJson(app('livewire')->getUpdateUri(), ['components' => [[
        'snapshot' => $snapshot,
        'updates' => [],
        'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
    ]]]);
}

describe('MN-05: el interruptor en «Parámetros»', function () {
    it('es un sí o no de Core, visible por omisión, que solo cambia su permiso', function () {
        $definition = app(SettingRegistry::class)->get(AcademicLoadVisibility::SETTING);

        expect($definition)
            ->module->toBe('core')
            ->type->toBe(SettingType::Boolean)
            ->default->toBeTrue()
            ->permission->toBe(CorePermission::AcademicLoadToggle)
            ->permissionOnly->toBeTrue()
            ->and(app(AcademicLoadVisibility::class)->isHidden())->toBeFalse();
    });

    it('sus dos permisos son candados del admin: ningún puesto los recibe', function () {
        $registry = app(PermissionRegistry::class);

        expect($registry->isAdminOnly(CorePermission::AcademicLoadToggle))->toBeTrue()
            ->and($registry->isAdminOnly(CorePermission::AcademicLoadViewHidden))->toBeTrue()
            ->and($registry->grants(Role::Admin, CorePermission::AcademicLoadToggle))->toBeTrue()
            ->and($registry->grants(Role::Admin, CorePermission::AcademicLoadViewHidden))->toBeTrue();

        $giving = new class(app()) extends ModuleServiceProvider
        {
            public function grants(): array
            {
                return [Role::Subdireccion->value => [CorePermission::AcademicLoadToggle]];
            }
        };

        expect(fn () => PermissionRegistry::fromModules([...app()->getProviders(ModuleServiceProvider::class), $giving]))->toThrow(LogicException::class);
    });

    it('el admin lo apaga desde la pantalla y queda en la bitácora con el valor anterior', function () {
        Livewire::actingAs($this->admin)->test('core::pages.settings')
            ->assertSet('values.core__academic_load_visible', true)
            ->set('values.core__academic_load_visible', false)
            ->call('save', 'core')
            ->assertHasNoErrors();

        $log = Activity::query()->where('description', 'like', 'Cambió el parámetro «Mostrar la carga académica»%')->sole();

        expect(Setting::query()->where('scope', SettingScope::Global)->where('key', AcademicLoadVisibility::SETTING)->sole()->value)->toBeFalse()
            ->and($log->causer?->is($this->admin))->toBeTrue()
            ->and($log->attribute_changes['old']['value'])->toBeTrue()
            ->and($log->attribute_changes['attributes']['value'])->toBeFalse()
            ->and(app(AcademicLoadVisibility::class)->isHidden())->toBeTrue();
    });

    it('Subdirección lo ve en solo lectura y, aunque tenga «cambiar los parámetros», guardar le responde 403', function () {
        $extra = new class(app()) extends ModuleServiceProvider
        {
            public function grants(): array
            {
                return [Role::Subdireccion->value => [CorePermission::SettingUpdate]];
            }
        };
        app()->instance(PermissionRegistry::class, PermissionRegistry::fromModules([...app()->getProviders(ModuleServiceProvider::class), $extra]));
        app()->forgetScopedInstances();

        $component = Livewire::actingAs($this->subdireccion)->test('core::pages.settings');

        expect(textOf(domOf($component)->querySelector('[data-testid="readonly-core"]')))->toBe('Solo lectura: estos parámetros los cambia el admin.')
            ->and($this->subdireccion->can('update', Setting::class))->toBeTrue();

        $component->set('values.core__academic_load_visible', false)->call('save', 'core')->assertForbidden();
        Livewire::actingAs($this->subdireccion)->test('core::pages.settings')->call('resetToDefault', AcademicLoadVisibility::SETTING)->assertForbidden();

        expect(Setting::query()->where('key', AcademicLoadVisibility::SETTING)->exists())->toBeFalse();
    });

    it('el cambio se nota en la misma petición: la lectura guardada se olvida', function () {
        expect(app(AcademicLoadVisibility::class)->isHidden())->toBeFalse();

        setAcademicLoadVisible(false);
        expect(app(AcademicLoadVisibility::class)->isHidden())->toBeTrue();

        setAcademicLoadVisible(true);
        expect(app(AcademicLoadVisibility::class)->isHidden())->toBeFalse();
    });

    it('lo leído vale para una petición: la siguiente vuelve a leerlo, aunque el cambio no pase por la pantalla', function () {
        $this->actingAs($this->subdireccion)->get(route('staffing.closing-board'))->assertOk();

        Setting::query()->create(['scope' => SettingScope::Global, 'scope_id' => null, 'key' => AcademicLoadVisibility::SETTING, 'value' => false]);

        $this->get(route('staffing.closing-board'))->assertForbidden();
    });

    it('un valor guardado que no es sí o no cuenta como visible', function () {
        Setting::query()->create(['scope' => SettingScope::Global, 'scope_id' => null, 'key' => AcademicLoadVisibility::SETTING, 'value' => 'no']);

        expect(app(AcademicLoadVisibility::class)->isHidden())->toBeFalse();
    });
});

describe('MN-05: el menú', function () {
    it('con la carga visible, cada puesto ve su Carga académica como hoy', function () {
        expect(loadVisibilityMenuOf($this->coordinador))->toHaveKey('carga_academica')
            ->and(loadVisibilityMenuOf($this->coordinador)['carga_academica'])->toContain('Horarios publicados')
            ->and(loadVisibilityMenuOf($this->subdireccion)['carga_academica'])->toContain('Precisión de la proyección', 'Horarios publicados')
            ->and(loadVisibilityMenuOf($this->admin)['carga_academica'])->toContain('Propuesta de carrera', 'Corridas del motor', 'Horarios publicados');
    });

    it('oculta, el coordinador, el jefe, el docente y Subdirección no ven el área, y el resto de su menú sigue igual', function () {
        $before = array_map(fn (User $user): array => loadVisibilityMenuOf($user), [$this->coordinador, $this->jefe, $this->docente, $this->subdireccion]);

        setAcademicLoadVisible(false);

        foreach ([$this->coordinador, $this->jefe, $this->docente, $this->subdireccion] as $index => $user) {
            $menu = loadVisibilityMenuOf($user);
            unset($before[$index]['carga_academica']);

            expect($menu)->not->toHaveKey('carga_academica')
                ->and($menu)->toBe($before[$index]);
        }
    });

    it('oculta, el menú pintado no trae el área ni sus entradas, así que el buscador no las encuentra', function () {
        setAcademicLoadVisible(false);
        $this->actingAs($this->coordinador);

        $page = domOf(Livewire::test('core::components.navigation'));

        expect($page->querySelector('#nexus-menu [data-menu-area="carga_academica"]'))->toBeNull()
            ->and($page->querySelector('#nexus-menu a[href="'.route('published.index').'"]'))->toBeNull()
            ->and($page->querySelector('#nexus-menu a[href="'.route('scheduling.editor').'"]'))->toBeNull();
    });

    it('oculta, el admin la sigue viendo entera, marcada «Oculta» con el motivo', function () {
        $visible = loadVisibilityMenuOf($this->admin)['carga_academica'];
        setAcademicLoadVisible(false);
        $this->actingAs($this->admin);

        $entries = collect(app(Navigation::class)->visibleByArea(app(Actor::class)))
            ->firstWhere('area', NavigationArea::CargaAcademica)['groups'];
        $reasons = collect($entries)->flatMap(fn (array $group): array => $group['entries'])
            ->map(fn (MenuEntry $entry): ?string => $entry->hiddenReason)->unique()->values()->all();
        $icon = domOf(Livewire::test('core::components.navigation'))->querySelector('#nexus-menu [data-menu-area="carga_academica"] [data-menu-flip="icon"]');

        expect(loadVisibilityMenuOf($this->admin)['carga_academica'])->toBe($visible)
            ->and($reasons)->toBe([AcademicLoadVisibility::HIDDEN_REASON])
            ->and($icon->getAttribute('aria-label'))->toBe('Carga académica, oculta para los demás');
    });

    it('oculta, el admin lee el aviso en la pantalla, y a los demás nada los lleva a ella', function () {
        setAcademicLoadVisible(false);

        $this->actingAs($this->admin);
        $note = domOf(Livewire::test('core::components.hidden-notice', ['route' => 'scheduling.editor']))->querySelector('[role="note"]');

        expect(textOf($note))->toBe('Oculta para los demás. La carga académica está oculta en Parámetros. Tú la ves porque administras Nexus; en el menú de los demás no aparece.');
    });

    it('oculta, la banda no ubica la carga para quien no la ve, y al admin sí', function () {
        setAcademicLoadVisible(false);

        $this->actingAs($this->coordinador);
        expect(app(PageTrailQuery::class)->forUrl('/scheduling/editor', app(Actor::class)))->toBeNull();

        $this->actingAs($this->admin);
        app(Actor::class)->refresh();
        expect(app(PageTrailQuery::class)->forUrl('/scheduling/editor', app(Actor::class)))->toBe(['area' => 'Carga académica', 'title' => 'Horarios']);
    });
});

describe('MN-05: las rutas', function () {
    // Deriva: compara la lista con las rutas del área y con las de los módulos de la carga por el prefijo de su nombre
    // (`academic-load.`, `published.`, `scheduling.`, `staffing.`, `solver.`); una ruta de carga con otro prefijo no la ve.
    it('la lista nombra rutas que existen y cubre las del área Carga académica y todas las de sus módulos; ninguna otra área lleva a ellas', function () {
        $items = collect(app(Navigation::class)->items());
        $routesOf = fn (NavigationItem $item): array => [$item->route, ...$item->childRoutes];
        // La orden de selección corre aunque la carga esté oculta, 1.33.
        $alwaysVisible = ['staffing.my-load', 'staffing.offer', 'staffing.selection.admin'];
        $inArea = $items->filter(fn (NavigationItem $item): bool => $item->area === NavigationArea::CargaAcademica)->flatMap($routesOf)->unique();
        $elsewhere = $items->reject(fn (NavigationItem $item): bool => $item->area === NavigationArea::CargaAcademica)->flatMap($routesOf)->unique();
        $ofLoadModules = collect(array_keys(Route::getRoutes()->getRoutesByName()))
            ->filter(fn (string $name): bool => Str::startsWith($name, ['academic-load.', 'published.', 'scheduling.', 'staffing.', 'solver.']))
            ->diff($alwaysVisible);

        expect(array_values(array_filter(AcademicLoadVisibility::ROUTES, fn (string $name): bool => ! Route::has($name))))->toBe([])
            ->and($inArea->diff(AcademicLoadVisibility::ROUTES)->values()->all())->toBe([])
            ->and($ofLoadModules->diff(AcademicLoadVisibility::ROUTES)->values()->all())->toBe([])
            ->and($elsewhere->diff($alwaysVisible)->intersect(AcademicLoadVisibility::ROUTES)->values()->all())->toBe([])
            ->and(array_intersect($alwaysVisible, AcademicLoadVisibility::ROUTES))->toBe([]);
    });

    it('Inicio y el mapa nunca se bloquean, aunque alguien los ponga en la lista', function () {
        expect(AcademicLoadVisibility::isLoadRoute('scheduling.editor'))->toBeTrue()
            ->and(AcademicLoadVisibility::isLoadRoute('home'))->toBeFalse()
            ->and(AcademicLoadVisibility::isLoadRoute('map.algo'))->toBeFalse()
            ->and(AcademicLoadVisibility::isLoadRoute('home', ['home', 'map.algo']))->toBeFalse()
            ->and(AcademicLoadVisibility::isLoadRoute('map.algo', ['home', 'map.algo']))->toBeFalse()
            ->and(AcademicLoadVisibility::isLoadRoute(null))->toBeFalse()
            ->and(array_filter(AcademicLoadVisibility::ROUTES, fn (string $name): bool => Str::is(AcademicLoadVisibility::NEVER_HIDDEN, $name)))->toBe([]);
    });

    it('oculta, Inicio y una ruta del mapa siguen abriendo para el coordinador y para el invitado', function () {
        Route::middleware('web')->get('/mapa-de-prueba', fn (): string => 'mapa')->name('map.algo');
        app('router')->getRoutes()->refreshNameLookups();
        setAcademicLoadVisible(false);

        $this->get('/mapa-de-prueba')->assertOk()->assertSee('mapa');
        $this->actingAs($this->coordinador)->get('/mapa-de-prueba')->assertOk();
        $this->actingAs($this->coordinador)->get(route('home'))->assertOk();
    });

    it('oculta, las que piden sesión responden 403 al coordinador, al jefe, al docente y a Subdirección', function () {
        setAcademicLoadVisible(false);
        $protected = array_values(array_filter(AcademicLoadVisibility::ROUTES, loadVisibilityNeedsLogin(...)));

        expect($protected)->toContain('academic-load.proposal', 'scheduling.editor', 'solver.runs', 'published.export');

        foreach ([$this->coordinador, $this->jefe, $this->docente, $this->subdireccion] as $user) {
            foreach ($protected as $name) {
                expect($this->actingAs($user)->get(loadVisibilityUrlOf($name))->status())->toBe(403, "{$name} para {$user->username}");
            }
        }
    });

    it('oculta, «Mi carga» y «Oferta» siguen abriendo (200) para el docente, y sus acciones de Livewire funcionan (MN-05, desde el 20 de noviembre de 2026)', function () {
        $teacher = Teacher::factory()->create(['user_id' => $this->docente->id]);
        setAcademicLoadVisible(false);

        foreach (['staffing.my-load' => 'staffing::pages.my-load', 'staffing.offer' => 'staffing::pages.offer'] as $name => $component) {
            $page = $this->actingAs($this->docente)->get(route($name))->assertOk();

            loadVisibilityRefresh(loadVisibilitySnapshotIn($page, $component))->assertOk();
        }

        expect($teacher)->not->toBeNull();
    });

    it('oculta, un aviso de Mi carga se ve en la campana y sale por correo, y los de la carga siguen retenidos', function () {
        $this->docente->forceFill(['email' => 'docente@itcj.edu.mx'])->save();
        setAcademicLoadVisible(false);
        $this->docente->notify(new HiddenLoadNotice('Mira la oferta', 'staffing.offer'));
        $this->docente->notify(new HiddenLoadNotice('Tu horario publicado', 'published.index'));
        $this->docente->notify(new HiddenMyLoadMailNotice);
        $this->docente->notify(new HiddenLoadMailNotice);

        Livewire::actingAs($this->docente)->test('core::components.bell')
            ->assertSee('Mira la oferta')
            ->assertSee('Cambió el horario de tus grupos')
            ->assertDontSee('Tu horario publicado');

        expect(MailLog::query()->pluck('subject')->all())->toBe(['Cambió el horario de tus grupos'])
            ->and(is_subclass_of(ChangeRequestMovedTeacherGroups::class, AcademicLoadNotice::class))->toBeFalse()
            ->and(is_subclass_of(ChangeAlternativeMovesCareerGroup::class, AcademicLoadNotice::class))->toBeTrue();
    });

    it('oculta, las públicas responden 404 al invitado y a quien tiene sesión', function () {
        setAcademicLoadVisible(false);
        $public = array_values(array_filter(AcademicLoadVisibility::ROUTES, fn (string $name): bool => ! loadVisibilityNeedsLogin($name)));

        expect($public)->toBe(['published.index', 'published.semester', 'published.teacher', 'published.room', 'published.semester.pdf', 'published.teacher.pdf', 'published.room.pdf']);

        foreach ($public as $name) {
            $this->get(loadVisibilityUrlOf($name))->assertNotFound();
        }

        foreach ([$this->coordinador, $this->docente] as $user) {
            $this->actingAs($user)->get(loadVisibilityUrlOf('published.index'))->assertNotFound();
        }
    });

    it('oculta, el invitado que pide una pantalla con sesión va al login, como siempre', function () {
        setAcademicLoadVisible(false);

        $this->get(route('scheduling.editor'))->assertRedirect(route('login'));
    });

    it('oculta, el admin sigue entrando, con el aviso de que los demás no la ven', function () {
        setAcademicLoadVisible(false);

        $page = domOf($this->actingAs($this->admin)->get(route('staffing.closing-board'))->assertOk());

        expect(textOf($page->querySelector('#nexus-content [role="note"]')))->toContain('La carga académica está oculta en Parámetros')
            ->and($page->querySelector('#nexus-menu [data-menu-area="carga_academica"]'))->not->toBeNull();

        $this->get(route('published.index'))->assertOk();
    });

    it('visible, todo abre como hoy', function () {
        $this->get(route('published.index'))->assertOk();
        $this->actingAs($this->subdireccion)->get(route('staffing.closing-board'))->assertOk();
        $this->actingAs($this->docente)->get(route('solver.runs'))->assertForbidden();
    });

    it('oculta, una acción de Livewire en una página de la carga ya abierta también se niega', function () {
        $snapshot = loadVisibilitySnapshotIn($this->actingAs($this->subdireccion)->get(route('staffing.closing-board'))->assertOk(), 'staffing::pages.closing-board');

        loadVisibilityRefresh($snapshot)->assertOk();

        setAcademicLoadVisible(false);
        // Cada petición empieza con el estado de Livewire limpio (Octane lo limpia); en la prueba, las dos van seguidas.
        Livewire::flushState();

        loadVisibilityRefresh($snapshot)->assertForbidden();
    });

    it('oculta, una acción de Livewire en la consulta pública ya abierta le responde 404 al invitado', function () {
        $snapshot = loadVisibilitySnapshotIn($this->get(route('published.index'))->assertOk(), 'academic-load::pages.published.index');

        loadVisibilityRefresh($snapshot)->assertOk();

        setAcademicLoadVisible(false);
        Livewire::flushState();

        loadVisibilityRefresh($snapshot)->assertNotFound();
    });
});

describe('MN-05: la campana', function () {
    beforeEach(function () {
        $this->coordinador->notify(new HiddenLoadNotice('Tu horario cambió', 'scheduling.editor'));
        $this->coordinador->notify(new HiddenLoadNotice('Catálogos por validar', 'catalog-validation.review'));
        $this->coordinador->notify(new HiddenLoadNotice('Aviso sin pantalla', null));
    });

    it('oculta, no cuenta ni muestra los avisos que llevan a la carga; no se borran y vuelven al mostrarla', function () {
        setAcademicLoadVisible(false);

        Livewire::actingAs($this->coordinador)->test('core::components.bell')
            ->assertSeeHtml('aria-label="Notificaciones: 2 sin leer"')
            ->assertSee('Catálogos por validar')
            ->assertSee('Aviso sin pantalla')
            ->assertDontSee('Tu horario cambió');

        Livewire::actingAs($this->coordinador)->test('core::pages.notifications')->assertDontSee('Tu horario cambió');

        setAcademicLoadVisible(true);

        expect($this->coordinador->notifications()->count())->toBe(3);
        Livewire::actingAs($this->coordinador)->test('core::components.bell')
            ->assertSeeHtml('aria-label="Notificaciones: 3 sin leer"')
            ->assertSee('Tu horario cambió');
    });

    it('oculta, abrir uno que ya estaba en pantalla lo avisa en lugar de llevar a un 403', function () {
        $component = Livewire::actingAs($this->coordinador)->test('core::components.bell');
        setAcademicLoadVisible(false);

        $component->call('open', $this->coordinador->notifications()->where('data->title', 'Tu horario cambió')->value('id'))
            ->assertNoRedirect();

        expect(lastToastOf($component))->toMatchArray(['type' => 'warning', 'title' => 'La carga académica no está disponible por ahora.']);
    });

    it('oculta, «Marcar todas como leídas» no marca los avisos de la carga: vuelven como nuevos al mostrarla', function () {
        setAcademicLoadVisible(false);
        $this->actingAs($this->coordinador);

        expect(app(MarkAllNotificationsRead::class)->handle($this->coordinador))->toBe(2)
            ->and($this->coordinador->unreadNotifications()->pluck('data')->pluck('title')->all())->toBe(['Tu horario cambió']);

        setAcademicLoadVisible(true);

        Livewire::actingAs($this->coordinador)->test('core::components.bell')
            ->assertSeeHtml('aria-label="Notificaciones: 1 sin leer"')
            ->assertSee('Tu horario cambió');
    });

    it('oculta, sus avisos no salen por correo, a nadie; la campana los guarda igual y vuelven al mostrarla', function () {
        $this->docente->forceFill(['email' => 'docente@itcj.edu.mx'])->save();
        $this->admin->forceFill(['email' => 'admin@itcj.edu.mx'])->save();
        setAcademicLoadVisible(false);

        $this->docente->notify(new HiddenLoadMailNotice);
        $this->admin->notify(new HiddenLoadMailNotice);

        expect(MailLog::query()->count())->toBe(0)
            ->and($this->docente->notifications()->where('data->title', 'Cambió tu carga')->count())->toBe(1);

        setAcademicLoadVisible(true);
        $this->docente->notify(new HiddenLoadMailNotice);

        expect(MailLog::query()->pluck('to')->all())->toBe(['docente@itcj.edu.mx']);
    });

    it('los avisos de la carga que salen por correo llevan la marca de Core', function () {
        $unmarked = [];

        foreach (['AcademicLoad', 'Scheduling', 'Staffing', 'Solver'] as $module) {
            foreach (glob(app_path("Modules/{$module}/Notifications/*.php")) as $file) {
                $class = "App\\Modules\\{$module}\\Notifications\\".basename($file, '.php');

                // El aviso al docente lleva a «Mi carga», que siempre se ve: no es de la carga que se oculta.
                if (method_exists($class, 'toMail') && ! is_subclass_of($class, AcademicLoadNotice::class) && $class !== ChangeRequestMovedTeacherGroups::class) {
                    $unmarked[] = $class;
                }
            }
        }

        expect($unmarked)->toBe([]);
    });

    it('oculta, el admin sigue viendo y abriendo los suyos', function () {
        $this->admin->notify(new HiddenLoadNotice('Tu horario cambió', 'scheduling.editor'));
        setAcademicLoadVisible(false);

        Livewire::actingAs($this->admin)->test('core::components.bell')
            ->assertSee('Tu horario cambió')
            ->call('open', $this->admin->notifications()->value('id'))
            ->assertRedirect(route('scheduling.editor'));
    });
});
