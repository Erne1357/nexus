<?php

use App\Modules\Imports\Templates\RoomsTemplate;

/*
| Lo que normaliza la plantilla `rooms` (tarea 1.15) antes de validar: claves de salón y edificio sin espacios y
| en mayúsculas, el tipo por nombre sin acentos ni mayúsculas (S11) y la capacidad como entero aunque el archivo
| traiga separadores de miles.
*/

function roomRow(array $changes = []): array
{
    return (new RoomsTemplate)->normalize([
        'building' => ' ea ',
        'code' => ' ea - 101 ',
        'name' => ' Aula   101 ',
        'type' => 'Aula',
        'capacity' => '40',
        'department' => ' xa ',
        ...$changes,
    ]);
}

it('deja las claves sin espacios y en mayúsculas, y el nombre recortado', function () {
    expect(roomRow())->toBe([
        'building' => 'EA',
        'code' => 'EA-101',
        'name' => 'Aula 101',
        'type' => 'aula',
        'capacity' => 40,
        'department' => 'XA',
    ]);
});

it('reconoce el tipo por nombre, sin acentos ni mayúsculas (S11)', function (string $given, string $type) {
    expect(roomRow(['type' => $given])['type'])->toBe($type);
})->with([
    ['AULA', 'aula'],
    [' Laboratorio ', 'laboratorio'],
    ['TALLER', 'taller'],
    ['Sala de Cómputo', 'sala_computo'],
    ['SALA DE COMPUTO', 'sala_computo'],
    ['Centro de Cómputo', 'sala_computo'],
    ['Auditorio', 'auditorio'],
]);

it('deja el tipo desconocido como vino, para que lo reporte la validación', function () {
    expect(roomRow(['type' => 'Gimnasio'])['type'])->toBe('Gimnasio');
});

it('lee la capacidad con separadores de miles y como número de Excel', function (mixed $given, mixed $capacity) {
    expect(roomRow(['capacity' => $given])['capacity'])->toBe($capacity);
})->with([
    ['1,200', 1200],
    ['1 200', 1200],
    [35.0, 35],
    ['35.0', 35],
    [60, 60],
    ['0', 0],
    ['muchos', 'muchos'],
]);

it('deja vacío lo que viene vacío', function () {
    $row = roomRow(['name' => '', 'department' => null, 'building' => '  ']);

    expect($row['name'])->toBeNull()->and($row['department'])->toBeNull()->and($row['building'])->toBeNull();
});
