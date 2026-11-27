<?php

use App\Modules\Students\Enums\ChainColor;

/*
| Color de cada cadena de seriación en la retícula del alumno (tarea 1.6; DESIGN.md, "Retícula del alumno"): la
| muestra de la leyenda, con los tokens `chain-1` a `chain-12` del tema. Con más de doce cadenas los colores se
| repiten.
*/

it('da a cada cadena su color en orden, y los repite después del duodécimo', function (int $index, ChainColor $color) {
    expect(ChainColor::forChain($index))->toBe($color);
})->with([
    [0, ChainColor::Chain1],
    [1, ChainColor::Chain2],
    [11, ChainColor::Chain12],
    [12, ChainColor::Chain1],
]);

it('da a cada color la clase de la muestra de la leyenda con su propio token', function (int $number) {
    expect(ChainColor::forChain($number - 1)->swatchClasses())->toBe("border-chain-{$number}");
})->with(range(1, 12));
