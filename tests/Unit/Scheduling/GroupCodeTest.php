<?php

use App\Modules\Scheduling\Rules\GroupCode;

/*
| GroupCode: la clave de un grupo sigue la nomenclatura del SII (reglas-de-negocio.md, "Grupos y horarios"): la letra
| es la hora de inicio, A = 7:00 … O = 21:00; sin sesiones lleva un número provisional.
*/

it('da la letra del SII según la hora de inicio', function (int $minutes, string $letter) {
    expect(GroupCode::letterFor($minutes))->toBe($letter);
})->with([
    'A a las 07:00' => [7 * 60, 'A'],
    'A a las 07:59' => [7 * 60 + 59, 'A'],
    'B a las 08:30' => [8 * 60 + 30, 'B'],
    'D a las 10:00' => [10 * 60, 'D'],
    'O a las 21:00' => [21 * 60, 'O'],
]);

it('antes de las 07:00 toma la A y desde las 21:00 la O (supuesto por confirmar)', function () {
    expect(GroupCode::letterFor(6 * 60))->toBe('A')
        ->and(GroupCode::letterFor(0))->toBe('A')
        ->and(GroupCode::letterFor(22 * 60))->toBe('O')
        ->and(GroupCode::letterFor(23 * 60 + 59))->toBe('O');
});

it('la clave provisional es el primer número consecutivo que nadie usa', function () {
    expect(GroupCode::provisional([]))->toBe('1')
        ->and(GroupCode::provisional(['1', '2']))->toBe('3')
        ->and(GroupCode::provisional(['2', 'D', '3']))->toBe('1')
        ->and(GroupCode::provisional(['1', '3']))->toBe('2');
});

it('con sesiones toma la letra, y si otro grupo ya la tiene le pone el siguiente número libre', function () {
    expect(GroupCode::forStart(10 * 60, []))->toBe('D')
        ->and(GroupCode::forStart(10 * 60, ['A', '1']))->toBe('D')
        ->and(GroupCode::forStart(10 * 60, ['D']))->toBe('D2')
        ->and(GroupCode::forStart(10 * 60, ['D', 'D2']))->toBe('D3')
        ->and(GroupCode::forStart(10 * 60, ['D', 'D3']))->toBe('D2');
});

it('un grupo sin sesiones vuelve a un número provisional', function () {
    expect(GroupCode::forStart(null, ['1', 'D']))->toBe('2')
        ->and(GroupCode::forStart(null, []))->toBe('1');
});
