<?php

use App\Modules\Imports\Sii\SiiFileRead;

/*
| Lo que dejó leer un archivo del SII: una incidencia por archivo y tipo (malformados, reparados, anotación al
| final), con la cuenta y los números de línea, nunca el contenido del renglón.
*/

it('sin nada que reportar no da incidencias', function () {
    expect((new SiiFileRead('alumnos.csv', [], [], []))->incidents())->toBe([])
        ->and((new SiiFileRead('alumnos.csv', [], [], []))->unreadable())->toBe(0);
});

it('reporta los renglones malformados como error con sus líneas', function () {
    $read = new SiiFileRead('alumnos.csv', [3, 9], [], []);

    expect($read->incidents())->toBe([[
        'level' => 'error',
        'ref' => 'malformados',
        'message' => '2 renglón(es) que no se pudieron leer (otro número de columnas o una comilla sin cerrar): no se migran. Líneas 3, 9.',
    ]])->and($read->unreadable())->toBe(2);
});

it('reporta lo reparado y la anotación al final como aviso', function () {
    $incidents = (new SiiFileRead('alumnos.csv', [], [4], [10, 11]))->incidents();

    expect($incidents)->toBe([
        ['level' => 'warning', 'ref' => 'reparados', 'message' => '1 renglón(es) con coma decimal se repararon uniendo el número y se migran. Líneas 4.'],
        ['level' => 'warning', 'ref' => 'anotación al final', 'message' => '2 línea(s) que no son renglones después del último renglón en blanco: se ignoran como anotación al final del archivo. Líneas 10, 11.'],
    ]);
});

it('lista a lo más 50 líneas y dice cuántas más hay', function () {
    $message = (new SiiFileRead('alumnos.csv', range(2, 61), [], []))->incidents()[0]['message'];

    expect($message)->toStartWith('60 renglón(es)')
        ->toContain('Líneas 2, 3, ')
        ->toEndWith(', 50, 51 y 10 más.')
        ->not->toContain(' 52');
});
