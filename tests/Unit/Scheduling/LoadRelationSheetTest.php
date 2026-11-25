<?php

use App\Modules\Scheduling\Support\LoadRelationSheet;

/*
| LoadRelationSheet (1.40, R-PRY-1): lector del csv de la «Relación de materias». Fixtures sintéticos.
*/

function relationCsv(string $content): string
{
    $path = sys_get_temp_dir().'/'.uniqid('nexus-relation-', true).'.csv';
    register_shutdown_function(fn () => @unlink($path));
    file_put_contents($path, $content);

    return $path;
}

it('lee igual el csv en latin1 y en UTF-8 y reconoce que es una relación', function () {
    $latin = __DIR__.'/../../Fixtures/imports/relacion-materias-latin1.csv';
    $utf8 = __DIR__.'/../../Fixtures/imports/relacion-materias-utf8.csv';

    expect(LoadRelationSheet::isRelation($latin))->toBeTrue()
        ->and(LoadRelationSheet::isRelation($utf8))->toBeTrue()
        ->and(LoadRelationSheet::read($latin))->toEqual(LoadRelationSheet::read($utf8))
        ->and(LoadRelationSheet::read($utf8)[0]->subjectName)->toBe('FUNDAMENTOS DE PROGRAMACIÓN');
});

it('toma la clave del plan del inicio de CLAVE CARRERA y las columnas por nombre', function () {
    [$first] = LoadRelationSheet::read(__DIR__.'/../../Fixtures/imports/relacion-materias-utf8.csv');

    expect($first->line)->toBe(3)
        ->and($first->teacherName)->toBe('LUNA ANA')
        ->and($first->subjectKey)->toBe('AID-2501')
        ->and($first->letter)->toBe('C')
        ->and($first->hourRange)->toBe('9 - 10')
        ->and($first->semester)->toBe(1)
        ->and($first->planCode)->toBe('ISIC-2010-224')
        ->and($first->credits)->toBe(5)
        ->and($first->roomCode)->toBe('703')
        ->and($first->students)->toBe(30)
        ->and($first->space)->toBe('LABORATORIO')
        ->and($first->hasFixedSchedule())->toBeTrue();
});

it('marca GL y el rango #N/D como sin horario fijo, y deja nulos los vacíos', function () {
    $rows = LoadRelationSheet::read(__DIR__.'/../../Fixtures/imports/relacion-materias-utf8.csv');

    expect($rows)->toHaveCount(3)
        ->and($rows[1]->semester)->toBeNull()
        ->and($rows[1]->teacherName)->toBeNull()
        ->and($rows[1]->roomCode)->toBeNull()
        ->and($rows[1]->space)->toBe('SALON')
        ->and($rows[2]->letter)->toBe('GL')
        ->and($rows[2]->hourRange)->toBeNull()
        ->and($rows[2]->subjectName)->toBe('REDES')
        ->and($rows[2]->hasFixedSchedule())->toBeFalse();
});

it('ignora el título, los renglones vacíos y encuentra las columnas aunque cambie su orden', function () {
    $path = relationCsv("TITULO;;;\n;;;\nALUMNOS;CLAVE DE MATERIA;SALÓN;HORA;;CLAVE CARRERA\n20;AAA-1;A1;B;8 - 9;ISIC-2010-224. X\n;;;;;\n");
    $rows = LoadRelationSheet::read($path);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->line)->toBe(4)
        ->and($rows[0]->students)->toBe(20)
        ->and($rows[0]->subjectKey)->toBe('AAA-1')
        ->and($rows[0]->roomCode)->toBe('A1')
        ->and($rows[0]->hourRange)->toBe('8 - 9')
        ->and($rows[0]->planCode)->toBe('ISIC-2010-224');
});

it('no reconoce como relación un csv sin el encabezado ni un xlsx', function () {
    expect(LoadRelationSheet::isRelation(relationCsv("A;B\n1;2\n")))->toBeFalse()
        ->and(LoadRelationSheet::isRelation('/no/existe.csv'))->toBeFalse();
});
