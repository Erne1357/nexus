<?php

use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Events\SettingsUpdated;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\User;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Settings\SettingDefinition;
use App\Modules\Core\Settings\SettingRegistry;
use App\Modules\Core\Settings\SettingType;
use App\Modules\Staffing\Rules\RankingWeights;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Fixtures\Authorization\FakePermission;

/*
| «Parámetros» (`/configuracion/parametros`, core::pages.settings): CF-S7. Una tarjeta por módulo, «Guardar» por tarjeta y
| «Volver al valor por omisión» por parámetro. El admin cambia; quien solo ve, no; los demás puestos no entran.
*/

if (! function_exists('grantSettingsOnPage')) {
    /**
     * Cambia el registro de permisos por el real más una concesión extra a un puesto.
     *
     * @param  list<CorePermission>  $permissions
     */
    function grantSettingsOnPage(Role $role, array $permissions): void
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

if (! function_exists('declareOwnedSetting')) {
    /**
     * Suma al catálogo real un módulo de prueba («fakemod») con un parámetro que declara un permiso propio, dado de fábrica
     * a la Subdirección, y otro que no declara ninguno.
     */
    function declareOwnedSetting(): void
    {
        $fake = new class(app()) extends ModuleServiceProvider
        {
            public function permissions(): array
            {
                return [FakePermission::class];
            }

            public function grants(): array
            {
                return [Role::Subdireccion->value => [FakePermission::ViewAny]];
            }

            public function settings(): array
            {
                return [
                    new SettingDefinition('fakemod.owned', 'fakemod', 'Módulo de prueba', 'Tamaño propio', 'Ayuda', SettingType::Integer, 5, ['min:1'], permission: FakePermission::ViewAny, savedNotice: 'Se recalcula en segundo plano.'),
                ];
            }
        };

        $modules = [...app()->getProviders(ModuleServiceProvider::class), $fake];

        app()->instance(PermissionRegistry::class, PermissionRegistry::fromModules($modules));
        app()->instance(SettingRegistry::class, SettingRegistry::fromModules($modules));
        app()->forgetScopedInstances();
    }
}

function subdireccionUser(): User
{
    return User::factory()->withRole(Role::Subdireccion, ['campus_id' => Campus::factory()->create()->id])->create();
}

function storedSetting(string $key): mixed
{
    return Setting::query()->where('scope', SettingScope::Global)->whereNull('scope_id')->where('key', $key)->value('value');
}

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

it('el admin ve una tarjeta por módulo con sus parámetros, su valor por omisión y su ayuda en español', function () {
    $dom = domOf($this->actingAs($this->admin)->get('/configuracion/parametros')->assertOk());

    expect(textOf($dom->querySelector('[data-testid="settings-staffing"] h2')))->toBe('Asignación docente')
        ->and(textOf($dom->querySelector('[data-testid="settings-scheduling"] h2')))->toBe('Horarios')
        ->and(textOf($dom->querySelector('[data-testid="settings-demand"] h2')))->toBe('Proyección de demanda')
        ->and(textOf($dom->querySelector('[data-testid="setting-staffing.min_campus_travel_minutes"]')))
        ->toContain('Minutos mínimos para cambiar de campus')->toContain('Por omisión: 60 minutos')
        ->and($dom->querySelector('[data-testid="setting-staffing.weights"] input[type="number"]'))->not->toBeNull()
        ->and($dom->querySelector('[data-testid="reset-staffing.min_campus_travel_minutes"]'))->toBeNull();
});

it('CF-S7: guarda los parámetros de una tarjeta, muestra «Volver al valor por omisión» y deja bitácora', function () {
    Livewire::actingAs($this->admin)->test('core::pages.settings')
        ->assertSet('values.staffing__min_campus_travel_minutes', 60)
        ->set('values.staffing__min_campus_travel_minutes', '90')
        ->set('values.staffing__weights.preferente', '4.5')
        ->call('save', 'staffing')
        ->assertHasNoErrors()
        ->assertSee('Volver al valor por omisión');

    expect(storedSetting('staffing.min_campus_travel_minutes'))->toBe(90)
        ->and(RankingWeights::fromValue(storedSetting('staffing.weights'))->preferente)->toBe(4.5)
        ->and(Activity::query()->where('description', 'like', 'Cambió el parámetro%')->count())->toBe(2);
});

it('CF-S7: una tabla de opciones (el motor de cada modalidad) se elige de una lista y solo guarda una de sus opciones', function () {
    $dom = domOf($this->actingAs($this->admin)->get('/configuracion/parametros')->assertOk());

    expect($dom->querySelector('[data-testid="setting-solver.default_engine"] select'))->not->toBeNull()
        ->and(textOf($dom->querySelector('[data-testid="setting-solver.default_engine"]')))->toContain('Por omisión: Presencial: CP-SAT (servicio), EAD: Heurística (PHP)');

    Livewire::actingAs($this->admin)->test('core::pages.settings')
        ->assertSet('values.solver__default_engine.pres', 'cpsat')
        ->set('values.solver__default_engine.pres', 'magia')
        ->call('save', 'solver')
        ->assertHasErrors(['values.solver__default_engine.pres'])
        ->set('values.solver__default_engine.pres', 'heuristic')
        ->call('save', 'solver')
        ->assertHasNoErrors();

    expect(storedSetting('solver.default_engine'))->toEqual(['pres' => 'heuristic', 'ead' => 'heuristic', 'enl' => 'heuristic', 'tnmv' => 'heuristic']);
});

it('CF-S7: un valor inválido marca su campo y no guarda nada de la tarjeta', function () {
    Livewire::actingAs($this->admin)->test('core::pages.settings')
        ->set('values.staffing__min_campus_travel_minutes', '90')
        ->set('values.staffing__history_terms', '0')
        ->set('values.staffing__weights.apto', '-2')
        ->call('save', 'staffing')
        ->assertHasErrors(['values.staffing__history_terms', 'values.staffing__weights.apto'])
        ->assertHasNoErrors('values.staffing__min_campus_travel_minutes');

    expect(Setting::query()->count())->toBe(0);
});

it('CF-S7: «Volver al valor por omisión» borra el valor y la pantalla vuelve al de omisión', function () {
    Setting::query()->create(['scope' => SettingScope::Global, 'key' => 'demand.min_group_size', 'value' => 15]);

    Livewire::actingAs($this->admin)->test('core::pages.settings')
        ->assertSet('values.demand__min_group_size', 15)
        ->call('resetToDefault', 'demand.min_group_size')
        ->assertSet('values.demand__min_group_size', 10);

    expect(Setting::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'deleted')->count())->toBe(1);
});

it('CF-S7: un parámetro que admite no tener valor se vacía y vuelve al de omisión', function () {
    Livewire::actingAs($this->admin)->test('core::pages.settings')
        ->set('values.staffing__honorarios_max_hours', '25')
        ->call('save', 'staffing');
    expect(storedSetting('staffing.honorarios_max_hours'))->toBe(25);

    Livewire::actingAs($this->admin)->test('core::pages.settings')
        ->set('values.staffing__honorarios_max_hours', '')
        ->call('save', 'staffing')
        ->assertHasNoErrors();
    expect(storedSetting('staffing.honorarios_max_hours'))->toBeNull();
});

it('CF-S7: un valor guardado que ya no es válido se avisa y se usa el de omisión', function () {
    Setting::query()->create(['scope' => SettingScope::Global, 'key' => 'staffing.history_terms', 'value' => 'muchos']);

    Livewire::actingAs($this->admin)->test('core::pages.settings')
        ->assertSet('values.staffing__history_terms', 6)
        ->assertSee('El valor guardado no es válido');
});

it('CF-S7: un puesto sin permiso no entra a la página', function (Role $role, array $scope) {
    $user = User::factory()->withRole($role, ['campus_id' => Campus::factory()->create()->id, ...$scope])->create();

    $this->actingAs($user)->get('/configuracion/parametros')->assertForbidden();
})->with([
    'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
    'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
    'docente' => [Role::Docente, []],
]);

it('Subdirección ve la pantalla de fábrica y no tiene core.setting.update', function () {
    $user = subdireccionUser();

    $this->actingAs($user)->get('/configuracion/parametros')->assertOk();
    expect($user->can('update', Setting::class))->toBeFalse();
});

it('CF-03: con solo «ver», las tarjetas se pintan en solo lectura y las acciones se niegan', function () {
    $user = subdireccionUser();
    Setting::query()->create(['scope' => SettingScope::Global, 'key' => 'staffing.min_campus_travel_minutes', 'value' => 75]);

    $dom = domOf($this->actingAs($user)->get('/configuracion/parametros')->assertOk());

    expect($dom->querySelector('[data-testid="setting-staffing.min_campus_travel_minutes"] input'))->toBeNull()
        ->and(textOf($dom->querySelector('[data-testid="value-staffing.min_campus_travel_minutes"]')))->toContain('75')
        ->and($dom->querySelector('[data-testid="save-scheduling"]'))->toBeNull();

    Livewire::actingAs($user)->test('core::pages.settings')
        ->set('values.staffing__min_campus_travel_minutes', '20')
        ->call('save', 'staffing')->assertForbidden();
    Livewire::actingAs($user)->test('core::pages.settings')
        ->call('resetToDefault', 'staffing.min_campus_travel_minutes')->assertForbidden();

    expect(storedSetting('staffing.min_campus_travel_minutes'))->toBe(75);
});

it('pinta en solo lectura las tarjetas que el puesto no puede cambiar', function () {
    declareOwnedSetting();

    $dom = domOf($this->actingAs(subdireccionUser())->get('/configuracion/parametros')->assertOk());

    expect($dom->querySelector('[data-testid="setting-fakemod.owned"] input'))->not->toBeNull()
        ->and($dom->querySelector('[data-testid="save-fakemod"]'))->not->toBeNull()
        ->and($dom->querySelector('[data-testid="readonly-fakemod"]'))->toBeNull()
        ->and($dom->querySelector('[data-testid="setting-staffing.weights"] input'))->toBeNull()
        ->and($dom->querySelector('[data-testid="save-staffing"]'))->toBeNull()
        ->and(textOf($dom->querySelector('[data-testid="readonly-staffing"]')))->toBe('Solo lectura: estos parámetros los cambia el admin.');
});

it('deja a Subdirección cambiar un parámetro que declara un permiso suyo y no los demás', function () {
    declareOwnedSetting();
    $user = subdireccionUser();

    $page = Livewire::actingAs($user)->test('core::pages.settings')
        ->set('values.fakemod__owned', '9')
        ->call('save', 'fakemod')
        ->assertHasNoErrors();

    expect(json_encode($page->effects['xjs'], JSON_UNESCAPED_UNICODE))->toContain('Se recalcula en segundo plano.');

    expect(storedSetting('fakemod.owned'))->toBe(9)
        ->and($user->can('update', [Setting::class, app(SettingRegistry::class)->get('staffing.weights')]))->toBeFalse();
});

it('responde 403 si Subdirección guarda o restablece una tarjeta ajena llamando la acción a mano', function () {
    declareOwnedSetting();
    Setting::query()->create(['scope' => SettingScope::Global, 'key' => 'staffing.history_terms', 'value' => 8]);
    $user = subdireccionUser();

    Livewire::actingAs($user)->test('core::pages.settings')
        ->set('values.staffing__history_terms', '3')
        ->call('save', 'staffing')->assertForbidden();
    Livewire::actingAs($user)->test('core::pages.settings')
        ->call('resetToDefault', 'staffing.history_terms')->assertForbidden();

    expect(storedSetting('staffing.history_terms'))->toBe(8);
});

it('despacha SettingsUpdated solo con las llaves que cambiaron', function () {
    Event::fake([SettingsUpdated::class]);

    Livewire::actingAs($this->admin)->test('core::pages.settings')
        ->set('values.staffing__min_campus_travel_minutes', '90')
        ->call('save', 'staffing');

    Event::assertDispatchedTimes(SettingsUpdated::class, 1);
    Event::assertDispatched(SettingsUpdated::class, fn (SettingsUpdated $event): bool => $event->keys === ['staffing.min_campus_travel_minutes']);

    Livewire::actingAs($this->admin)->test('core::pages.settings')
        ->call('resetToDefault', 'staffing.min_campus_travel_minutes');

    Event::assertDispatchedTimes(SettingsUpdated::class, 2);
});

it('no despacha nada si nada cambió', function () {
    Event::fake([SettingsUpdated::class]);

    Livewire::actingAs($this->admin)->test('core::pages.settings')
        ->call('save', 'staffing')
        ->call('resetToDefault', 'staffing.min_campus_travel_minutes');

    Event::assertNotDispatched(SettingsUpdated::class);
});

it('la entrada «Parámetros» está en el menú del admin, en Configuración', function () {
    $html = $this->actingAs($this->admin)->get('/configuracion/parametros')->assertOk()->getContent();

    expect($html)->toContain('href="'.route('core.settings').'"')->toContain('Configuración');
});
