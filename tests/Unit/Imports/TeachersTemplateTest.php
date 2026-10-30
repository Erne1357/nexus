<?php

use App\Modules\Imports\Templates\TeachersTemplate;

/*
| Lo que normaliza la plantilla `teachers` antes de validar (tarea 10): el número de empleado como lo
| normaliza Faculty, las claves en mayúsculas, el activo del SII y las fechas dd/mm/aaaa a ISO.
*/

it('normaliza el número de empleado como Docentes y deja las claves en mayúsculas', function () {
    $row = (new TeachersTemplate)->normalize([
        'employee_number' => ' 12 345 ',
        'name' => ' Ana  López ',
        'department' => ' sc ',
        'campus' => 'c2',
        'email' => 'ana@example.test',
        'degree' => 'Maestría',
        'active' => 'Sí',
        'hired_on' => '05/08/2015',
    ]);

    expect($row)->toBe([
        'employee_number' => '12345',
        'name' => 'Ana López',
        'department' => 'SC',
        'campus' => 'C2',
        'email' => 'ana@example.test',
        'degree' => 'maestria',
        'active' => true,
        'hired_on' => '2015-08-05',
    ]);
});

it('pasa a texto el número de empleado que un Excel lee como número', function () {
    expect((new TeachersTemplate)->normalize(['employee_number' => 98765.0])['employee_number'])->toBe('98765');
});

it('reconoce el activo del SII y deja lo que no entiende para que la validación lo rechace', function (mixed $value, mixed $expected) {
    expect((new TeachersTemplate)->normalize(['active' => $value])['active'])->toBe($expected);
})->with([
    'Sí' => ['Sí', true],
    'si' => ['si', true],
    'activo' => ['ACTIVO', true],
    'uno' => ['1', true],
    'No' => ['No', false],
    'inactivo' => ['Inactivo', false],
    'cero' => ['0', false],
    'vacío' => [null, null],
    'raro' => ['tal vez', 'tal vez'],
]);

it('deja una fecha imposible o en otro formato tal cual para que la validación la rechace', function (string $value, string $expected) {
    expect((new TeachersTemplate)->normalize(['hired_on' => $value])['hired_on'])->toBe($expected);
})->with([
    'día 31 de febrero' => ['31/02/2015', '31/02/2015'],
    'ya en ISO' => ['2015-08-05', '2015-08-05'],
    'un dígito' => ['5/8/2015', '2015-08-05'],
]);

it('un grado desconocido se queda como texto', function () {
    expect((new TeachersTemplate)->normalize(['degree' => 'Posdoctorado'])['degree'])->toBe('Posdoctorado');
});
