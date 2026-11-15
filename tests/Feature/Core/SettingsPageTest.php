<?php

use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\User;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Staffing\Rules\RankingWeights;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

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
        ->and(textOf($dom->querySelector('[data-testid="settings-academic_load"] h2')))->toBe('Carga académica')
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
    'subdirección' => [Role::Subdireccion, []],
    'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
    'docente' => [Role::Docente, []],
]);

it('CF-03: con solo «ver», los campos y «Guardar» están deshabilitados y las acciones se niegan', function () {
    grantSettingsOnPage(Role::Subdireccion, [CorePermission::SettingView]);
    $user = User::factory()->withRole(Role::Subdireccion, ['campus_id' => Campus::factory()->create()->id])->create();
    Setting::query()->create(['scope' => SettingScope::Global, 'key' => 'demand.min_group_size', 'value' => 15]);

    $dom = domOf($this->actingAs($user)->get('/configuracion/parametros')->assertOk());

    expect($dom->querySelector('[data-testid="setting-demand.min_group_size"] input')->hasAttribute('disabled'))->toBeTrue()
        ->and($dom->querySelector('[data-testid="save-staffing"]'))->toBeNull();

    Livewire::actingAs($user)->test('core::pages.settings')
        ->set('values.demand__min_group_size', '20')
        ->call('save', 'academic_load')->assertForbidden();
    Livewire::actingAs($user)->test('core::pages.settings')
        ->call('resetToDefault', 'demand.min_group_size')->assertForbidden();

    expect(storedSetting('demand.min_group_size'))->toBe(15);
});

it('la entrada «Parámetros» está en el menú del admin, en Configuración', function () {
    $html = $this->actingAs($this->admin)->get('/configuracion/parametros')->assertOk()->getContent();

    expect($html)->toContain('href="'.route('core.settings').'"')->toContain('Configuración');
});
