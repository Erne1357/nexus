<?php

use App\Modules\Core\Support\SemesterGrid;

/*
| El acomodo de una retícula en una sola cuadrícula (DESIGN.md, "Retícula del alumno" y "Retícula del plan
| (editor)"): una columna por semestre; el renglón 1 es de los encabezados; luego el tronco común, cada materia
| en su columna, una debajo de otra; y después una banda por especialidad a todo el ancho, con sus materias
| debajo, cada una en su columna. Así los mosaicos de un mismo renglón se alinean entre columnas.
*/

it('acomoda el tronco común y luego cada especialidad bajo su banda, en el orden de sus bandas', function () {
    $grid = SemesterGrid::place(
        [
            1 => ['A' => null, 'B' => null, 'R1' => 'redes'],
            2 => ['C' => null, 'S2' => 'software', 'R2' => 'redes', 'R3' => 'redes'],
            3 => ['D' => null, 'E' => null, 'F' => null],
        ],
        ['redes', 'software'],
    );

    expect($grid->columns)->toBe([1 => 1, 2 => 2, 3 => 3])
        ->and($grid->rows)->toBe([
            'A' => 2, 'B' => 3, 'R1' => 6,
            'C' => 2, 'S2' => 9, 'R2' => 6, 'R3' => 7,
            'D' => 2, 'E' => 3, 'F' => 4,
        ])
        ->and($grid->bands)->toBe(['redes' => 5, 'software' => 8])
        ->and($grid->rowCount)->toBe(9);
});

it('una especialidad sin materias en un semestre deja vacías sus celdas de ese semestre', function () {
    $grid = SemesterGrid::place(
        [
            1 => ['A' => null],
            2 => ['B' => null, 'R2' => 'redes'],
            3 => ['C' => null],
            4 => ['R4' => 'redes', 'R5' => 'redes'],
        ],
        ['redes'],
    );

    expect($grid->rows)->toBe(['A' => 2, 'B' => 2, 'R2' => 4, 'C' => 2, 'R4' => 4, 'R5' => 5])
        ->and($grid->bands)->toBe(['redes' => 3])
        ->and($grid->rowCount)->toBe(5);
});

it('un semestre vacío conserva su columna y solo lleva su encabezado', function () {
    $grid = SemesterGrid::place([1 => ['A' => null], 2 => [], 3 => ['B' => null, 'C' => null]]);

    expect($grid->columns)->toBe([1 => 1, 2 => 2, 3 => 3])
        ->and($grid->rows)->toBe(['A' => 2, 'B' => 2, 'C' => 3])
        ->and($grid->bands)->toBe([])
        ->and($grid->rowCount)->toBe(3);
});

it('numera las columnas en orden de semestre aunque falte alguno, y una retícula vacía solo tiene encabezados', function () {
    expect(SemesterGrid::place([4 => ['G' => null], 1 => ['A' => null]])->columns)->toBe([1 => 1, 4 => 2])
        ->and(SemesterGrid::place([]))->columns->toBe([])->rows->toBe([])->bands->toBe([])->rowCount->toBe(1);
});

it('sin tronco común la primera banda va justo debajo de los encabezados', function () {
    $grid = SemesterGrid::place([1 => ['R1' => 'redes'], 2 => ['S2' => 'software']], ['redes', 'software']);

    expect($grid->bands)->toBe(['redes' => 2, 'software' => 4])
        ->and($grid->rows)->toBe(['R1' => 3, 'S2' => 5])
        ->and($grid->rowCount)->toBe(5);
});

it('no abre banda para una especialidad sin materias, y una que no está en el orden va al final', function () {
    $grid = SemesterGrid::place([1 => ['A' => null, 'X1' => 'quitada', 'R1' => 'redes']], ['redes', 'vacia']);

    expect($grid->bands)->toBe(['redes' => 3, 'quitada' => 5])
        ->and($grid->rows)->toBe(['A' => 2, 'X1' => 6, 'R1' => 4]);
});
