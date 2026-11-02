<?php

use App\Modules\AcademicLoad\Rules\ProposalAnomalies;

/*
| Anomalías de una fila de la propuesta (confirmado el 2 de noviembre de 2026): se marcan sin bloquear el envío.
| Más esperados que elegibles, y grupos con menos alumnos esperados que el mínimo por grupo.
*/

it('no marca una fila normal', function () {
    expect((new ProposalAnomalies(10))->for(eligible: 70, expected: 70, groups: 2))->toBe([]);
});

it('marca más esperados que elegibles', function () {
    expect((new ProposalAnomalies(10))->for(eligible: 98, expected: 105, groups: 3))->toBe(['105 esperados con 98 elegibles']);
});

it('marca grupos con menos alumnos que el mínimo, en singular y plural', function (int $expected, int $groups, string $message) {
    expect((new ProposalAnomalies(10))->for(eligible: 100, expected: $expected, groups: $groups))->toBe([$message]);
})->with([
    'un grupo' => [2, 1, '1 grupo para 2 alumnos'],
    'dos grupos' => [15, 2, '2 grupos para 15 alumnos'],
    'sin alumnos' => [0, 1, '1 grupo para 0 alumnos'],
    'un alumno' => [1, 1, '1 grupo para 1 alumno'],
]);

it('no marca una materia sin grupos pedidos', function () {
    expect((new ProposalAnomalies(10))->for(eligible: 5, expected: 5, groups: 0))->toBe([]);
});

it('marca las dos cosas a la vez', function () {
    expect((new ProposalAnomalies(10))->for(eligible: 3, expected: 5, groups: 1))->toBe(['5 esperados con 3 elegibles', '1 grupo para 5 alumnos']);
});

it('el mínimo exacto no es anomalía', function () {
    expect((new ProposalAnomalies(10))->for(eligible: 40, expected: 20, groups: 2))->toBe([]);
});

it('toma el mínimo del parámetro global y, si falta o es inválido, usa 10', function (mixed $value, int $minimum) {
    expect(ProposalAnomalies::fromValue($value)->minimum)->toBe($minimum);
})->with([
    'sin parámetro' => [null, 10],
    'válido' => [15, 15],
    'cero' => [0, 10],
    'texto' => ['abc', 10],
]);
