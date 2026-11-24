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
