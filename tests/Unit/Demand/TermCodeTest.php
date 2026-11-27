<?php

use App\Modules\Demand\Rules\TermCode;

/*
| Códigos de periodo del SII (`AAAAT`: 1 Ene-Jun, 2 Verano, 3 Ago-Dic) para la proyección (tarea 5.2).
*/

it('Ene-Jun y Ago-Dic son semestres ordinarios; verano no', function (string $code, bool $regular) {
    expect(TermCode::isRegular($code))->toBe($regular);
})->with([
    'Ene-Jun' => ['20271', true],
    'verano' => ['20262', false],
    'Ago-Dic' => ['20263', true],
]);

it('el semestre ordinario anterior salta el verano y cambia de año', function (string $code, string $previous) {
    expect(TermCode::previousRegular($code))->toBe($previous);
})->with([
    'de Ene-Jun al Ago-Dic del año anterior' => ['20271', '20263'],
    'de Ago-Dic al Ene-Jun del mismo año' => ['20263', '20261'],
    'de verano al Ene-Jun del mismo año' => ['20262', '20261'],
]);

it('rechaza un código que no es de periodo', function (string $code) {
    expect(fn () => TermCode::previousRegular($code))->toThrow(InvalidArgumentException::class);
})->with(['2027', '20274', 'abcde', '202710']);
