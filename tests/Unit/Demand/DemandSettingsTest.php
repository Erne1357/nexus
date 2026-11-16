<?php

use App\Modules\Demand\Rules\DemandSettings;
use App\Modules\Demand\Rules\SuggestedGroups;

/*
| Parámetros de la proyección de demanda (tarea 5.8; reglas-de-negocio.md, «Elegibilidad y demanda»):
| `demand.history_terms` (4, confirmado el 10 de noviembre de 2026), `demand.target_group_size` (35 y 25),
| `demand.min_group_size` (10) y `demand.highlight_threshold` (20 %, supuesto por confirmar). Un valor que falta o
| no es válido usa el de omisión.
*/

it('sin parámetros guardados usa los valores de omisión', function () {
    $settings = DemandSettings::fromValues([]);

    expect($settings)->toEqual(new DemandSettings)
        ->and($settings->historyTerms)->toBe(4)
        ->and($settings->groupSizes)->toEqual(new SuggestedGroups(35, 25))
        ->and($settings->minGroupSize)->toBe(10)
        ->and($settings->highlightThreshold)->toBe(20);
});

it('lee cada parámetro guardado', function () {
    $settings = DemandSettings::fromValues([
        DemandSettings::HISTORY_TERMS_SETTING => 6,
        DemandSettings::TARGET_GROUP_SIZE_SETTING => ['default' => 40, 'lab' => 20],
        DemandSettings::MIN_GROUP_SIZE_SETTING => 12,
        DemandSettings::HIGHLIGHT_THRESHOLD_SETTING => 30,
    ]);

    expect($settings->historyTerms)->toBe(6)
        ->and($settings->groupSizes)->toEqual(new SuggestedGroups(40, 20))
        ->and($settings->minGroupSize)->toBe(12)
        ->and($settings->highlightThreshold)->toBe(30);
});

it('un valor que no es un entero válido usa el de omisión', function (string $key, mixed $value) {
    expect(DemandSettings::fromValues([$key => $value]))->toEqual(new DemandSettings);
})->with([
    'semestres en cero' => [DemandSettings::HISTORY_TERMS_SETTING, 0],
    'semestres en texto' => [DemandSettings::HISTORY_TERMS_SETTING, 'cuatro'],
    'mínimo negativo' => [DemandSettings::MIN_GROUP_SIZE_SETTING, -3],
    'umbral en cero' => [DemandSettings::HIGHLIGHT_THRESHOLD_SETTING, 0],
    'umbral decimal' => [DemandSettings::HIGHLIGHT_THRESHOLD_SETTING, 12.5],
    'tamaños que no son tabla' => [DemandSettings::TARGET_GROUP_SIZE_SETTING, 35],
]);

it('una diferencia es grande si el capturado se aleja del sugerido el umbral o más', function (int $suggested, int $captured, bool $large) {
    expect((new DemandSettings(highlightThreshold: 20))->isLargeDifference($suggested, $captured))->toBe($large);
})->with([
    'igual' => [50, 50, false],
    'menos del umbral arriba' => [50, 59, false],
    'justo el umbral arriba' => [50, 60, true],
    'justo el umbral abajo' => [50, 40, true],
    'menos del umbral abajo' => [50, 41, false],
    'sugerido cero y capturado cero' => [0, 0, false],
    'sugerido cero y capturado algo' => [0, 1, true],
    'capturado cero con sugerido' => [5, 0, true],
]);

it('declara la definición de cada parámetro con el valor de omisión que usa al leerlo', function () {
    $defaults = [];

    foreach (DemandSettings::definitions() as $definition) {
        $defaults[$definition->key] = $definition->default;
    }

    expect(array_keys($defaults))->toEqualCanonicalizing([
        DemandSettings::HISTORY_TERMS_SETTING, DemandSettings::TARGET_GROUP_SIZE_SETTING, DemandSettings::MIN_GROUP_SIZE_SETTING, DemandSettings::HIGHLIGHT_THRESHOLD_SETTING,
    ])->and(DemandSettings::fromValues($defaults))->toEqual(new DemandSettings);
});
