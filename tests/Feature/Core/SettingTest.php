<?php

use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Setting;
use Illuminate\Database\UniqueConstraintViolationException;

it('no repite una clave global', function () {
    Setting::create(['scope' => SettingScope::Global, 'key' => 'hours_tolerance', 'value' => 2]);

    expect(fn () => Setting::create(['scope' => SettingScope::Global, 'key' => 'hours_tolerance', 'value' => 3]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('no repite una clave en el mismo campus', function () {
    Setting::create(['scope' => SettingScope::Campus, 'scope_id' => 1, 'key' => 'default_quota', 'value' => 35]);

    expect(fn () => Setting::create(['scope' => SettingScope::Campus, 'scope_id' => 1, 'key' => 'default_quota', 'value' => 40]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('permite la misma clave en alcances distintos', function () {
    Setting::create(['scope' => SettingScope::Global, 'key' => 'default_quota', 'value' => 35]);
    Setting::create(['scope' => SettingScope::Campus, 'scope_id' => 1, 'key' => 'default_quota', 'value' => 40]);
    Setting::create(['scope' => SettingScope::Campus, 'scope_id' => 2, 'key' => 'default_quota', 'value' => 30]);

    expect(Setting::where('key', 'default_quota')->count())->toBe(3);
});

it('guarda valores json escalares y compuestos', function () {
    $scalar = Setting::create(['scope' => SettingScope::Global, 'key' => 'hours_tolerance', 'value' => 2])->fresh();
    $weights = Setting::create(['scope' => SettingScope::Global, 'key' => 'ranking_weights', 'value' => ['history' => 0.6, 'evaluation' => 0.4]])->fresh();

    expect($scalar->scope)->toBe(SettingScope::Global)
        ->and($scalar->value)->toBe(2)
        ->and($weights->value)->toBe(['history' => 0.6, 'evaluation' => 0.4]);
});
