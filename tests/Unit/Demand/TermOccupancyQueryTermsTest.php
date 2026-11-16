<?php

use App\Modules\Demand\Queries\TermOccupancyQuery;

/*
| Los periodos del mismo tipo anteriores a uno (TermOccupancyQuery::sameTypeTermsBefore): códigos del SII `AAAAT`, con T = 1
| Ene-Jun, 2 Verano, 3 Ago-Dic. El tipo es el último dígito; el más reciente va primero.
*/

it('da los Ene-Jun anteriores, el más reciente primero', function () {
    expect(TermOccupancyQuery::sameTypeTermsBefore('20271', 4))->toBe(['20261', '20251', '20241', '20231']);
});

it('da los Ago-Dic anteriores sin mezclar otros tipos', function () {
    expect(TermOccupancyQuery::sameTypeTermsBefore('20273', 2))->toBe(['20263', '20253']);
});

it('da los veranos anteriores', function () {
    expect(TermOccupancyQuery::sameTypeTermsBefore('20272', 3))->toBe(['20262', '20252', '20242']);
});

it('cruza el cambio de siglo y de década sin saltarse años', function () {
    expect(TermOccupancyQuery::sameTypeTermsBefore('20001', 2))->toBe(['19991', '19981']);
});

it('con cero periodos o menos no da ninguno', function (int $terms) {
    expect(TermOccupancyQuery::sameTypeTermsBefore('20271', $terms))->toBe([]);
})->with([0, -3]);

it('rechaza un código de periodo mal formado', function (string $code) {
    TermOccupancyQuery::sameTypeTermsBefore($code, 2);
})->with(['2027', '20274', '20270', 'abcde', '202711', ''])->throws(InvalidArgumentException::class);
