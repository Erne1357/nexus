<?php

use App\Modules\Staffing\Rules\SelectionOrder;

function selEntry(string $key, string $source, ?int $tier, ?float $seniority, ?string $hiredOn, string $name = 'Nombre'): array
{
    return ['key' => $key, 'source' => $source, 'tier' => $tier, 'seniority' => $seniority, 'hired_on' => $hiredOn, 'name' => $name];
}

it('SEL-07: mismo escalón y misma antigüedad comparten turno', function () {
    $ranked = SelectionOrder::rank([
        selEntry('a', 'csv', 0, 10.0, null),
        selEntry('b', 'csv', 0, 10.0, null),
        selEntry('c', 'csv', 0, 5.0, null),
        selEntry('d', 'csv', 1, 30.0, null),
    ]);

    expect($ranked)->toBe([
        ['key' => 'a', 'position' => 1, 'slot_index' => 0],
        ['key' => 'b', 'position' => 2, 'slot_index' => 0],
        ['key' => 'c', 'position' => 3, 'slot_index' => 1],
        ['key' => 'd', 'position' => 4, 'slot_index' => 2],
    ]);
});

it('SEL-07: a igual escalón va primero la mayor antigüedad', function () {
    $ranked = SelectionOrder::rank([
        selEntry('junior', 'csv', 2, 3.0, null),
        selEntry('senior', 'csv', 2, 20.5, null),
    ]);

    expect(array_column($ranked, 'key'))->toBe(['senior', 'junior']);
});

it('SEL-06: TC con 4 fallas queda después de Asignatura sin fallas', function () {
    $ranked = SelectionOrder::rank([
        selEntry('tc-fallas', 'csv', 4, 40.0, null),
        selEntry('asig', 'csv', 3, 1.0, null),
    ]);

    expect(array_column($ranked, 'key'))->toBe(['asig', 'tc-fallas']);
});

it('SEL-10: los que no vienen en el CSV van al final por fecha de contratación', function () {
    $ranked = SelectionOrder::rank([
        selEntry('nuevo-sin-fecha', 'appended', null, null, null, 'Zoe Prueba'),
        selEntry('nuevo-2022', 'appended', null, null, '2022-08-01', 'Luis Ejemplo'),
        selEntry('csv-peor', 'csv', 9, 0.0, null),
        selEntry('nuevo-2022-b', 'appended', null, null, '2022-08-01', 'Ana Ejemplo'),
        selEntry('nuevo-2019', 'appended', null, null, '2019-01-10', 'Eva Ejemplo'),
        selEntry('nuevo-sin-fecha-a', 'appended', null, null, null, 'Ada Prueba'),
    ]);

    expect(array_column($ranked, 'key'))->toBe(['csv-peor', 'nuevo-2019', 'nuevo-2022', 'nuevo-2022-b', 'nuevo-sin-fecha-a', 'nuevo-sin-fecha']);
    $slots = array_column($ranked, 'slot_index', 'key');
    expect($slots['nuevo-2022'])->toBe($slots['nuevo-2022-b'])
        ->and($slots['nuevo-2019'])->toBe($slots['csv-peor'] + 1)
        ->and($slots['nuevo-sin-fecha-a'])->not->toBe($slots['nuevo-sin-fecha']);
});

it('SEL-10: los nombres con acento se ordenan sin acentos en los empates', function () {
    $ranked = SelectionOrder::rank([
        selEntry('zeta', 'appended', null, null, null, 'Zoe Prueba'),
        selEntry('nandu', 'appended', null, null, null, 'Ñandú Prueba'),
        selEntry('alvaro', 'appended', null, null, null, 'Álvaro Prueba'),
        selEntry('beto', 'appended', null, null, null, 'Beto Prueba'),
    ]);

    expect(array_column($ranked, 'key'))->toBe(['alvaro', 'beto', 'nandu', 'zeta']);
});
