<?php

use App\Modules\Core\Actions\UpdateSettings;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Core\Settings\SettingRegistry;
use App\Modules\Demand\Jobs\ComputeDemandForecasts;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
| Parámetros de demanda (tarea 5.8b; DM-08b): Subdirección los cambia con `demand.setting.update` y guardar uno recalcula
| en segundo plano los periodos abiertos.
*/

function subdireccionForDemand(): User
{
    return User::factory()->withRole(Role::Subdireccion, ['campus_id' => Campus::factory()->create()->id])->create();
}

function openDemandTarget(string $code, string $modalityCode): TermOpening
{
    Modality::query()->where('code', $modalityCode)->update(['is_active' => true]);

    $term = Term::query()->where('code', $code)->first() ?? Term::factory()->create(['code' => $code, 'is_current' => true]);

    return TermOpening::factory()->create(['term_id' => $term->id, 'modality_id' => Modality::query()->where('code', $modalityCode)->value('id')]);
}

function saveDemandSettings(array $values): array
{
    $definitions = collect(app(SettingRegistry::class)->byModule()['demand'])->filter(fn ($definition): bool => array_key_exists($definition->key, $values))->values()->all();

    return app(UpdateSettings::class)->handle($definitions, $values);
}

it('Subdirección cambia el tamaño de grupo desde la pantalla de parámetros', function () {
    Livewire::actingAs(subdireccionForDemand())->test('core::pages.settings')
        ->set('values.demand__target_group_size.default', '30')
        ->call('save', 'demand')
        ->assertHasNoErrors();

    expect(Setting::query()->where('key', 'demand.target_group_size')->value('value'))->toMatchArray(['default' => 30]);
});

it('Subdirección no cambia los parámetros de otros módulos', function () {
    Livewire::actingAs(subdireccionForDemand())->test('core::pages.settings')
        ->call('save', 'staffing')
        ->assertForbidden();

    expect(Setting::query()->where('key', 'like', 'staffing.%')->count())->toBe(0);
});

it('encola el recálculo por periodo abierto y modalidad al guardar un parámetro de demanda', function () {
    Queue::fake();
    $a = openDemandTarget('20271', 'PRES');
    $b = openDemandTarget('20271', 'EAD');

    saveDemandSettings(['demand.min_group_size' => 15]);

    Queue::assertPushed(ComputeDemandForecasts::class, 2);
    Queue::assertPushed(ComputeDemandForecasts::class, fn (ComputeDemandForecasts $job): bool => $job->termId === $a->term_id && $job->modalityId === $a->modality_id);
    Queue::assertPushed(ComputeDemandForecasts::class, fn (ComputeDemandForecasts $job): bool => $job->modalityId === $b->modality_id);
});

it('no encola nada al guardar un parámetro de otro módulo', function () {
    Queue::fake();
    openDemandTarget('20271', 'PRES');

    app(UpdateSettings::class)->handle(collect(app(SettingRegistry::class)->byModule()['staffing'])->filter(fn ($d): bool => $d->key === 'staffing.min_campus_travel_minutes')->values()->all(), ['staffing.min_campus_travel_minutes' => 90]);

    Queue::assertNothingPushed();
});

it('no encola nada si se guarda sin cambios', function () {
    openDemandTarget('20271', 'PRES');
    saveDemandSettings(['demand.min_group_size' => 15]);
    Queue::fake();

    saveDemandSettings(['demand.min_group_size' => 15]);

    Queue::assertNothingPushed();
});
