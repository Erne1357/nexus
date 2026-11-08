<?php

use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Setting;
use App\Modules\Staffing\Rules\RankingWeights;
use App\Modules\Staffing\Rules\RankingWindowSizes;

function rankingSetting(string $key, mixed $value): void
{
    Setting::query()->create(['scope' => SettingScope::Global, 'scope_id' => null, 'key' => $key, 'value' => $value]);
}

it('Ranking docente: sin parámetros usa los valores por omisión', function () {
    expect(RankingWeights::fromSettings())->toEqual(new RankingWeights)
        ->and(RankingWindowSizes::fromSettings())->toEqual(new RankingWindowSizes(6, 2));
});

it('Ranking docente: lee los pesos y las ventanas de los parámetros globales', function () {
    rankingSetting('staffing.weights', ['preferente' => 5, 'evaluation' => 'x']);
    rankingSetting('staffing.history_terms', 4);
    rankingSetting('staffing.evaluation_terms', 3);

    $weights = RankingWeights::fromSettings();

    expect($weights->preferente)->toBe(5.0)
        ->and($weights->evaluation)->toBe(2.0)
        ->and(RankingWindowSizes::fromSettings())->toEqual(new RankingWindowSizes(4, 3));
});
