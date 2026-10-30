<?php

use App\Modules\Imports\Templates\PositionsTemplate;

/*
| Lo que normaliza la plantilla `positions` (tarea 10): el tipo de plaza por su nombre en el SII, las horas
| como número y las fechas dd/mm/aaaa a ISO.
*/

it('reconoce el tipo de plaza por el nombre del SII, sin importar acentos ni mayúsculas', function (string $name, string $kind) {
    expect((new PositionsTemplate)->normalize(['kind' => $name])['kind'])->toBe($kind);
})->with([
    'tiempo completo' => ['TIEMPO COMPLETO', 'tiempo_completo'],
    'tres cuartos' => ['3/4', 'tres_cuartos'],
    'tres cuartos de tiempo' => ['3/4 de tiempo', 'tres_cuartos'],
    'medio tiempo' => ['Medio Tiempo', 'medio_tiempo'],
    'asignatura' => ['ASIGNATURA', 'asignatura'],
    'honorarios' => ['Honorarios', 'honorarios'],
    'valor de Nexus' => ['tres_cuartos', 'tres_cuartos'],
]);

it('un tipo desconocido se queda como texto', function () {
    expect((new PositionsTemplate)->normalize(['kind' => 'Interino'])['kind'])->toBe('Interino');
});

it('normaliza el número de empleado, las horas y las fechas del SII', function () {
    $row = (new PositionsTemplate)->normalize([
        'employee_number' => ' 12 345',
        'kind' => 'asignatura',
        'base_hours' => '12',
        'valid_from' => '01/08/2026',
        'valid_to' => '31/12/2026',
    ]);

    expect($row)->toBe([
        'employee_number' => '12345',
        'kind' => 'asignatura',
        'base_hours' => 12,
        'valid_from' => '2026-08-01',
        'valid_to' => '2026-12-31',
    ]);
});

it('deja las horas que no son un entero para que la validación las rechace', function () {
    expect((new PositionsTemplate)->normalize(['base_hours' => 'diez'])['base_hours'])->toBe('diez');
});
