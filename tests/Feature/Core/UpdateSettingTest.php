<?php

use App\Modules\Core\Actions\ResetSetting;
use App\Modules\Core\Actions\UpdateSetting;
use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Settings\SettingRegistry;
use App\Modules\Staffing\Rules\RankingWeights;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| UpdateSetting y ResetSetting (CF-S7): validan con la definición del módulo, escriben la fila global o la borran, y dejan
| bitácora con el valor anterior y el nuevo.
*/

function storedGlobalSetting(string $key): mixed
{
    return Setting::query()->where('scope', SettingScope::Global)->whereNull('scope_id')->where('key', $key)->value('value');
}

function settingDefinitionOf(string $key)
{
    return app(SettingRegistry::class)->get($key);
}

it('CF-S7: guarda un valor válido en la fila global y lo deja en la bitácora con el anterior (el de omisión)', function () {
    app(UpdateSetting::class)->handle(settingDefinitionOf('staffing.extra_hours_tolerance'), '4');

    $log = Activity::query()->where('description', 'like', '%staffing.extra_hours_tolerance%')->sole();

    expect(storedGlobalSetting('staffing.extra_hours_tolerance'))->toBe(4)
        ->and($log->attribute_changes['old']['value'])->toBe(0)
        ->and($log->attribute_changes['attributes']['value'])->toBe(4)
        ->and($log->event)->toBe('created');
});

it('CF-S7: cambiar un valor ya guardado deja el anterior de la fila', function () {
    $definition = settingDefinitionOf('staffing.min_campus_travel_minutes');
    app(UpdateSetting::class)->handle($definition, 30);
    app(UpdateSetting::class)->handle($definition, 45);

    $log = Activity::query()->where('event', 'updated')->sole();

    expect(storedGlobalSetting('staffing.min_campus_travel_minutes'))->toBe(45)
        ->and($log->attribute_changes['old']['value'])->toBe(30)
        ->and(Setting::query()->where('key', 'staffing.min_campus_travel_minutes')->count())->toBe(1);
});

it('CF-S7: un valor inválido no se guarda ni se registra', function (string $key, mixed $value, string $error) {
    expect(fn () => app(UpdateSetting::class)->handle(settingDefinitionOf($key), $value))->toThrow(ValidationException::class)
        ->and(Setting::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
})->with([
    'entero con decimales' => ['staffing.history_terms', 2.5, 'value'],
    'semestres en cero' => ['staffing.evaluation_terms', 0, 'value'],
    'tolerancia negativa' => ['staffing.extra_hours_tolerance', -1, 'value'],
    'texto' => ['scheduling.weekly_hours_tolerance', 'mucho', 'value'],
    'tamaño de grupo en cero' => ['demand.min_group_size', 0, 'value'],
]);

it('CF-S7: los pesos del ranking se guardan como el objeto que lee RankingWeights y no admiten negativos ni faltantes', function () {
    $definition = settingDefinitionOf('staffing.weights');

    app(UpdateSetting::class)->handle($definition, ['per_group_taught' => '2', 'preferente' => '5', 'apto' => '1.5', 'evaluation' => '0']);

    expect(RankingWeights::fromValue(storedGlobalSetting('staffing.weights')))->toEqual(new RankingWeights(2.0, 5.0, 1.5, 0.0));

    expect(fn () => app(UpdateSetting::class)->handle($definition, ['per_group_taught' => -1, 'preferente' => 1, 'apto' => 1, 'evaluation' => 1]))->toThrow(ValidationException::class)
        ->and(fn () => app(UpdateSetting::class)->handle($definition, ['preferente' => 1]))->toThrow(ValidationException::class)
        ->and(RankingWeights::fromValue(storedGlobalSetting('staffing.weights'))->perGroupTaught)->toBe(2.0);
});

it('CF-S7: guardar el mismo valor no escribe ni registra', function () {
    $definition = settingDefinitionOf('staffing.extra_hours_tolerance');

    app(UpdateSetting::class)->handle($definition, 0);
    expect(Setting::query()->count())->toBe(0)->and(Activity::query()->count())->toBe(0);

    app(UpdateSetting::class)->handle($definition, 3);
    app(UpdateSetting::class)->handle($definition, '3');
    expect(Activity::query()->count())->toBe(1);
});

it('CF-S7: vaciar un parámetro que admite no tener valor lo vuelve al de omisión', function () {
    $definition = settingDefinitionOf('staffing.honorarios_max_hours');

    app(UpdateSetting::class)->handle($definition, 20);
    expect(storedGlobalSetting('staffing.honorarios_max_hours'))->toBe(20);

    app(UpdateSetting::class)->handle($definition, '');
    expect(storedGlobalSetting('staffing.honorarios_max_hours'))->toBeNull()
        ->and(Setting::query()->count())->toBe(0);
});

it('CF-S7: volver al valor por omisión borra la fila y deja el valor anterior en la bitácora', function () {
    $definition = settingDefinitionOf('demand.target_group_size');
    app(UpdateSetting::class)->handle($definition, ['default' => 40, 'lab' => 20]);

    app(ResetSetting::class)->handle($definition);

    $log = Activity::query()->where('event', 'deleted')->sole();

    expect(Setting::query()->count())->toBe(0)
        ->and($log->attribute_changes['old']['value'])->toEqual(['default' => 40, 'lab' => 20])
        ->and($log->attribute_changes['attributes']['value'])->toBe(['default' => 35, 'lab' => 25]);
});

it('CF-S7: volver al valor por omisión de uno que no se cambió no hace nada', function () {
    app(ResetSetting::class)->handle(settingDefinitionOf('staffing.history_terms'));

    expect(Activity::query()->count())->toBe(0);
});
