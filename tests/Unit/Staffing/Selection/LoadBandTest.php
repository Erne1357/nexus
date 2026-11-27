<?php

use App\Modules\Staffing\Rules\LoadBand;

it('SEL-05: la banda de carga es de más o menos 2 horas, inclusiva', function (int $hours, string $expected) {
    expect(LoadBand::classify($hours, 20))->toBe($expected);
})->with([
    'dentro abajo' => [18, 'within'],
    'dentro arriba' => [22, 'within'],
    'base' => [20, 'within'],
    'arriba' => [23, 'over'],
    'abajo' => [17, 'under'],
]);

it('PL-03 y SEL-05: con plaza administrativa no hay banda, ni con 0 h ni con 10 h', function (int $hours) {
    expect(LoadBand::classify($hours, 0, true))->toBe('within');
})->with([0, 10, 30]);
