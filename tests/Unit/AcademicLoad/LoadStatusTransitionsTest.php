<?php

use App\Modules\AcademicLoad\Enums\LoadStatus;

/*
| El diagrama de "Máquina de estados de la carga académica" (docs/proceso/proceso-propuesto.md) como datos:
| cada par permitido y todos los demás, la matriz completa de 8 x 8.
*/

const ALLOWED_LOAD_TRANSITIONS = [
    'draft' => ['proposed'],
    'proposed' => ['staffing'],
    'staffing' => ['adjusting', 'closed'],
    'adjusting' => ['staffing'],
    'closed' => ['approved', 'published'],
    'approved' => ['published', 'adjusting'],
    'published' => ['reanalysis'],
    'reanalysis' => ['published'],
];

dataset('load transitions matrix', function () {
    foreach (LoadStatus::cases() as $from) {
        foreach (LoadStatus::cases() as $to) {
            yield "{$from->value} a {$to->value}" => [$from, $to, in_array($to->value, ALLOWED_LOAD_TRANSITIONS[$from->value], true)];
        }
    }
});

it('permite exactamente las transiciones del diagrama y ninguna otra', function (LoadStatus $from, LoadStatus $to, bool $allowed) {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with('load transitions matrix');

it('lista los destinos de cada estado en transitions()', function () {
    foreach (LoadStatus::cases() as $from) {
        expect(array_map(fn (LoadStatus $to): string => $to->value, $from->transitions()))->toBe(ALLOWED_LOAD_TRANSITIONS[$from->value]);
    }
});
