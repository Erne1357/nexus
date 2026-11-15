<?php

use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\LoginNotice;
use App\Modules\Core\Models\LoginShortcut;
use App\Modules\Core\Models\User;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Queries\LoginScreenQuery;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| «Avisos y atajos» (`/configuracion/avisos-y-atajos`, core::pages.login-content): CF-S6, con la vigencia de LG-01 y LG-02.
| El admin maneja las dos pestañas; un puesto sin permiso no entra; con solo uno de los permisos, ve solo esa pestaña.
*/

if (! function_exists('grantLoginContent')) {
    /**
     * Cambia el registro de permisos por el real más una concesión extra a un puesto.
     *
     * @param  list<CorePermission>  $permissions
     */
    function grantLoginContent(Role $role, array $permissions): void
    {
        $extra = new class(app()) extends ModuleServiceProvider
        {
            /** @var array<string, list<CorePermission>> */
            public array $extra = [];

            public function grants(): array
            {
                return $this->extra;
            }
        };
        $extra->extra = [$role->value => $permissions];

        app()->instance(PermissionRegistry::class, PermissionRegistry::fromModules([...app()->getProviders(ModuleServiceProvider::class), $extra]));
        app()->forgetScopedInstances();
    }
}

beforeEach(function () {
    Storage::fake('public');
    $this->admin = User::factory()->admin()->create();
});

it('el admin abre la página con las pestañas Avisos y Atajos y la barra estándar de catálogo', function () {
    LoginNotice::factory()->create(['title' => 'Inscripciones']);

    $dom = domOf($this->actingAs($this->admin)->get('/configuracion/avisos-y-atajos')->assertOk());
    $toolbar = $dom->querySelector('[data-testid="catalog-toolbar"]');

    expect(textOf($dom->querySelector('[data-testid="tab-notices"]')))->toBe('Avisos')
        ->and(textOf($dom->querySelector('[data-testid="tab-shortcuts"]')))->toBe('Atajos')
        ->and($toolbar->querySelector('input[aria-label="Buscar avisos"]'))->not->toBeNull()
        ->and($toolbar->querySelector('select[aria-label="Estado"]'))->not->toBeNull()
        ->and(textOf($toolbar->querySelector('.btn-primary')))->toBe('Dar de alta')
        ->and($dom->querySelector('button[aria-label="Editar Inscripciones"]'))->not->toBeNull()
        ->and($dom->querySelector('button[aria-label="Acciones de Inscripciones"][aria-haspopup="menu"]'))->not->toBeNull();
});

it('CF-S6: un puesto sin permiso no entra a la página', function (Role $role, array $scope) {
    $user = User::factory()->withRole($role, ['campus_id' => Campus::factory()->create()->id, ...$scope])->create();

    $this->actingAs($user)->get('/configuracion/avisos-y-atajos')->assertForbidden();
})->with([
    'subdirección' => [Role::Subdireccion, []],
    'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
    'docente' => [Role::Docente, []],
]);

it('CF-03: con solo el permiso de atajos, la página muestra solo la pestaña Atajos y no deja manejar avisos', function () {
    grantLoginContent(Role::Subdireccion, [CorePermission::LoginShortcutView, CorePermission::LoginShortcutManage]);
    $user = User::factory()->withRole(Role::Subdireccion, ['campus_id' => Campus::factory()->create()->id])->create();
    LoginShortcut::factory()->create(['title' => 'Sitio']);
    $notice = LoginNotice::factory()->create(['title' => 'Aviso oculto']);

    $dom = domOf($this->actingAs($user)->get('/configuracion/avisos-y-atajos')->assertOk());

    expect($dom->querySelector('[data-testid="tab-notices"]'))->toBeNull()
        ->and(textOf($dom->querySelector('[data-testid="tab-shortcuts"]')))->toBe('Atajos')
        ->and(textOf($dom->querySelector('main')))->toContain('Sitio')->not->toContain('Aviso oculto');

    // Aunque la petición traiga la pestaña de avisos, vuelve a la que sí puede ver y no toca el aviso.
    Livewire::actingAs($user)->test('core::pages.login-content')
        ->set('tab', 'notices')
        ->call('toggleActive', $notice->id)
        ->assertNotFound();

    expect($notice->fresh()->is_active)->toBeTrue();
});

it('CF-03: con solo «ver», los controles de manejo están deshabilitados y las acciones se niegan', function () {
    grantLoginContent(Role::Subdireccion, [CorePermission::LoginNoticeView]);
    $user = User::factory()->withRole(Role::Subdireccion, ['campus_id' => Campus::factory()->create()->id])->create();
    $notice = LoginNotice::factory()->create(['title' => 'Aviso']);

    $dom = domOf($this->actingAs($user)->get('/configuracion/avisos-y-atajos')->assertOk());
    expect($dom->querySelector('[data-testid="catalog-toolbar"] .btn-primary')->hasAttribute('disabled'))->toBeTrue();

    Livewire::actingAs($user)->test('core::pages.login-content')
        ->call('create')->assertForbidden();
    Livewire::actingAs($user)->test('core::pages.login-content')
        ->call('edit', $notice->id)->assertForbidden();
    Livewire::actingAs($user)->test('core::pages.login-content')
        ->call('delete', $notice->id)->assertForbidden();
    Livewire::actingAs($user)->test('core::pages.login-content')
        ->call('move', $notice->id, 1)->assertForbidden();

    expect($notice->fresh())->not->toBeNull();
});

it('CF-S6: da de alta un aviso con foto desde el drawer y muestra su vigencia', function () {
    Livewire::actingAs($this->admin)->test('core::pages.login-content')
        ->call('create')
        ->assertSet('showDrawer', true)
        ->set('title', 'Reinscripciones')
        ->set('link_url', 'https://www.tecnm.mx')
        ->set('starts_at', '2027-01-10T08:00')
        ->set('ends_at', '2027-01-20T18:00')
        ->set('image', UploadedFile::fake()->create('foto.png', 50, 'image/png'))
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showDrawer', false)
        ->assertSee('Reinscripciones')
        ->assertSee('10/01/2027 08:00');

    $notice = LoginNotice::query()->sole();
    expect($notice->image_path)->toStartWith('login/notices/')
        ->and($notice->starts_at->format('Y-m-d H:i'))->toBe('2027-01-10 08:00');
});

it('CF-S6: muestra el motivo en el drawer cuando el enlace no es http(s) o el fin va antes del inicio', function () {
    Livewire::actingAs($this->admin)->test('core::pages.login-content')
        ->call('create')
        ->set('title', 'X')
        ->set('link_url', 'javascript:alert(1)')
        ->set('starts_at', '2027-01-10T08:00')
        ->set('ends_at', '2027-01-09T08:00')
        ->call('save')
        ->assertHasErrors(['link_url', 'ends_at'])
        ->assertSet('showDrawer', true);

    expect(LoginNotice::query()->count())->toBe(0);
});

it('CF-S6: edita un aviso, lo desactiva, lo sube y lo borra desde la lista', function () {
    $a = LoginNotice::factory()->create(['title' => 'A', 'sort_order' => 1]);
    $b = LoginNotice::factory()->create(['title' => 'B', 'sort_order' => 2]);

    $page = Livewire::actingAs($this->admin)->test('core::pages.login-content')
        ->call('edit', $a->id)
        ->assertSet('title', 'A')
        ->set('title', 'A2')
        ->call('save')
        ->assertHasNoErrors();
    expect($a->fresh()->title)->toBe('A2');

    $page->call('move', $b->id, -1);
    expect(LoginNotice::query()->orderBy('sort_order')->pluck('title')->all())->toBe(['B', 'A2']);

    $page->call('toggleActive', $b->id);
    expect($b->fresh()->is_active)->toBeFalse();

    $page->call('delete', $a->id);
    expect(LoginNotice::query()->pluck('title')->all())->toBe(['B']);
});

it('CF-S6: da de alta un atajo de descarga con su pdf en la pestaña Atajos', function () {
    Livewire::actingAs($this->admin)->test('core::pages.login-content')
        ->set('tab', 'shortcuts')
        ->call('create')
        ->set('kind', 'descarga')
        ->set('title', 'Guía de inscripción')
        ->set('file', UploadedFile::fake()->create('guia.pdf', 200, 'application/pdf'))
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Guía de inscripción')
        ->assertSee('Descarga');

    expect(LoginShortcut::query()->sole()->file_path)->toStartWith('login/shortcuts/');
});

it('LG-01 y LG-02: la lista dice si hoy se ve en la pantalla de acceso y por qué no', function () {
    $this->travelTo('2027-01-15 10:00');
    LoginNotice::factory()->create(['title' => 'Vigente']);
    LoginNotice::factory()->create(['title' => 'Apagado', 'is_active' => false]);
    LoginNotice::factory()->create(['title' => 'Futuro', 'starts_at' => '2027-02-01']);
    LoginNotice::factory()->create(['title' => 'Vencido', 'ends_at' => '2027-01-01']);

    $rows = collect(domOf($this->actingAs($this->admin)->get('/configuracion/avisos-y-atajos'))->querySelectorAll('table tbody tr'))
        ->mapWithKeys(fn ($tr): array => [textOf($tr->querySelectorAll('td')[1]) => textOf($tr->querySelectorAll('td')[3])]);

    expect($rows->all())->toBe([
        'Vigente' => 'Sí', 'Apagado' => 'No: desactivado', 'Futuro' => 'No: aún no empieza', 'Vencido' => 'No: ya terminó',
    ]);
});

it('LG-01 y LG-02: lo que se da de alta en el panel aparece en la pantalla de acceso solo mientras está activo y vigente', function () {
    $this->travelTo('2027-01-15 10:00');
    $page = Livewire::actingAs($this->admin)->test('core::pages.login-content')
        ->call('create')->set('title', 'Vigente')->call('save')
        ->call('create')->set('title', 'Futuro')->set('starts_at', '2027-02-01T08:00')->call('save')
        ->set('tab', 'shortcuts')
        ->call('create')->set('title', 'Sitio')->set('url', 'https://www.tecnm.mx')->call('save');

    $screen = app(LoginScreenQuery::class);
    expect(array_column($screen->notices(), 'title'))->toBe(['Vigente'])
        ->and(array_column($screen->shortcuts(), 'title'))->toBe(['Sitio']);

    $notice = LoginNotice::query()->where('title', 'Vigente')->sole();
    $page->set('tab', 'notices')->call('toggleActive', $notice->id);
    expect($screen->notices())->toBe([]);

    $page->call('toggleActive', $notice->id)->call('delete', $notice->id);
    expect($screen->notices())->toBe([]);

    $this->travelTo('2027-02-02 10:00');
    expect(array_column($screen->notices(), 'title'))->toBe(['Futuro']);
});

it('la entrada «Avisos y atajos» está en el menú del admin, en Configuración', function () {
    $html = $this->actingAs($this->admin)->get('/configuracion/avisos-y-atajos')->assertOk()->getContent();

    expect($html)->toContain('href="'.route('core.login-content').'"')->toContain('Configuración');
});

it('CF-S6: con un filtro activo, «Subir» y «Bajar» se deshabilitan con su motivo', function () {
    LoginNotice::factory()->create(['title' => 'Alfa', 'sort_order' => 1]);
    LoginNotice::factory()->create(['title' => 'Beta', 'sort_order' => 2]);

    $moves = fn ($html): array => collect(domOf($html)->querySelectorAll('table tbody tr:last-child [role="menuitem"]'))
        ->filter(fn ($i): bool => str_starts_with(textOf($i), 'Subir') || str_starts_with(textOf($i), 'Bajar'))
        ->map(fn ($i): string => textOf($i).'|'.$i->getAttribute('aria-disabled'))->values()->all();

    expect($moves(Livewire::actingAs($this->admin)->test('core::pages.login-content')->html()))
        ->toBe(['Subir|', 'Bajar Ya es el último.|true']);

    $filtered = Livewire::actingAs($this->admin)->test('core::pages.login-content')->set('search', 'Beta')->html();

    expect($moves($filtered))->toBe(['Subir Quita el filtro para cambiar el orden.|true', 'Bajar Quita el filtro para cambiar el orden.|true']);
});
