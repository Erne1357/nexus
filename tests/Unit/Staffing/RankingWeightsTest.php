<?php

use App\Modules\Staffing\Rules\RankingWeights;
use App\Modules\Staffing\Rules\RankingWindowSizes;

it('Ranking docente: los pesos por omisión son 1, 3, 1 y 2', function () {
    $weights = new RankingWeights;

    expect([$weights->perGroupTaught, $weights->preferente, $weights->apto, $weights->evaluation])->toBe([1.0, 3.0, 1.0, 2.0]);
});

it('Ranking docente: cada peso cae a su valor por omisión si falta o no es un número mayor o igual a cero', function () {
    $weights = RankingWeights::fromValue(['per_group_taught' => 2, 'preferente' => -1, 'apto' => 'x', 'evaluation' => 0]);

    expect([$weights->perGroupTaught, $weights->preferente, $weights->apto, $weights->evaluation])->toBe([2.0, 3.0, 1.0, 0.0]);
});

it('Ranking docente: un valor que no es un objeto usa todos los pesos por omisión', function (mixed $value) {
    expect(RankingWeights::fromValue($value))->toEqual(new RankingWeights);
})->with([[null], ['abc'], [5], [[]]]);

it('Ranking docente: acepta decimales en los pesos', function () {
    expect(RankingWeights::fromValue(['evaluation' => 1.5])->evaluation)->toBe(1.5);
});

it('Ranking docente: las ventanas son 6 y 2 por omisión y solo aceptan enteros de 1 en adelante', function () {
    expect(RankingWindowSizes::fromValues(null, null))->toEqual(new RankingWindowSizes(6, 2))
        ->and(RankingWindowSizes::fromValues(4, 3))->toEqual(new RankingWindowSizes(4, 3))
        ->and(RankingWindowSizes::fromValues(0, -2))->toEqual(new RankingWindowSizes(6, 2))
        ->and(RankingWindowSizes::fromValues('x', 1.5))->toEqual(new RankingWindowSizes(6, 2));
});
