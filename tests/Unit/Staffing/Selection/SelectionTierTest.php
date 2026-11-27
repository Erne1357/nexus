<?php

use App\Modules\Staffing\Rules\SelectionTier;

it('SEL-06: la plaza fija el escalón base con las horas exactas del CSV', function (int $hours, int $tier) {
    expect(SelectionTier::plazaTier($hours))->toBe($tier);
})->with([
    'TC' => [40, 0],
    'tres cuartos' => [30, 1],
    'medio tiempo' => [20, 2],
    'asignatura 35' => [35, 3],
    'asignatura 12' => [12, 3],
]);

it('SEL-06: cada criterio fallido baja un escalón, sin piso', function () {
    expect(SelectionTier::tier(40, 1))->toBe(1)
        ->and(SelectionTier::tier(12, 2))->toBe(5)
        ->and(SelectionTier::tier(40, 4))->toBe(4);
});
