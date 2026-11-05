<?php

use App\Modules\Imports\Sii\SiiCsvReader;
use App\Modules\Imports\Sii\SiiFile;
use Tests\Unit\Imports\Sii\SiiFixtures;

require_once __DIR__.'/SiiFixtures.php';

beforeEach(function () {
    $this->dir = SiiFixtures::dir();
    $this->path = $this->dir.'/alumnos_todos.csv';
});

afterEach(fn () => SiiFixtures::clean($this->dir));

it('lee comillas simples y recorta los CHAR', function () {
    SiiFixtures::write($this->path, SiiFile::Alumnos, [
        SiiFixtures::line(SiiFile::Alumnos, ['no_de_control' => "'10 '", 'carrera' => "'ISC   '"]),
    ]);

    $reader = new SiiCsvReader($this->path, SiiFile::Alumnos);
    $rows = iterator_to_array($reader->rows());

    expect($rows)->toHaveCount(1)
        ->and($rows[2]['no_de_control'])->toBe('10')
        ->and($rows[2]['carrera'])->toBe('ISC')
        ->and($reader->header())->toBe(SiiFile::Alumnos->headers());
});

it('convierte Windows-1252 a UTF-8', function () {
    SiiFixtures::write($this->path, SiiFile::Alumnos, [
        SiiFixtures::line(SiiFile::Alumnos, ['escuela_procedencia' => "'DISE\xD1O'"]),
    ]);

    $rows = iterator_to_array((new SiiCsvReader($this->path, SiiFile::Alumnos))->rows());

    expect($rows[2]['escuela_procedencia'])->toBe('DISEÑO');
});

it('quita el BOM del encabezado', function () {
    SiiFixtures::write($this->path, SiiFile::Alumnos, [SiiFixtures::line(SiiFile::Alumnos)]);
    file_put_contents($this->path, "\xEF\xBB\xBF".file_get_contents($this->path));

    expect((new SiiCsvReader($this->path, SiiFile::Alumnos))->header()[0])->toBe('no_de_control');
});

it('respeta la comilla doblada dentro de un texto', function () {
    SiiFixtures::write($this->path, SiiFile::Alumnos, [
        SiiFixtures::line(SiiFile::Alumnos, ['escuela_procedencia' => "'D''ARCY, JR'"]),
    ]);

    $rows = iterator_to_array((new SiiCsvReader($this->path, SiiFile::Alumnos))->rows());

    expect($rows[2]['escuela_procedencia'])->toBe("D'ARCY, JR");
});

it('trata una comilla vacía como cadena vacía', function () {
    SiiFixtures::write($this->path, SiiFile::Alumnos, [
        SiiFixtures::line(SiiFile::Alumnos),
        SiiFixtures::line(SiiFile::Alumnos, ['especialidad' => "''"]),
    ]);

    $rows = iterator_to_array((new SiiCsvReader($this->path, SiiFile::Alumnos))->rows());

    expect($rows[3]['especialidad'])->toBe('');
});

it('une la coma decimal de los promedios', function () {
    SiiFixtures::write($this->path, SiiFile::Alumnos, [
        SiiFixtures::line(SiiFile::Alumnos),
        // Los demás números van vacíos: con enteros contiguos la coma decimal sería ambigua.
        SiiFixtures::line(SiiFile::Alumnos, [
            'promedio_periodo_anterior' => '',
            'promedio_aritmetico_acumulado' => '87,50',
            'creditos_aprobados' => '',
            'creditos_cursados' => '',
            'promedio_final_alcanzado' => '',
            'indice_reprobacion_acumulado' => '',
        ]),
    ]);

    $reader = new SiiCsvReader($this->path, SiiFile::Alumnos);
    $rows = iterator_to_array($reader->rows());

    expect($rows)->toHaveCount(2)
        ->and($rows[3]['promedio_aritmetico_acumulado'])->toBe('87.50')
        ->and($reader->repaired())->toBe(1)
        ->and($reader->malformed())->toBe([]);
});

it('no repara un renglón ambiguo', function () {
    SiiFixtures::write($this->path, SiiFile::Alumnos, [
        SiiFixtures::line(SiiFile::Alumnos, ['promedio_periodo_anterior' => '80,5', 'promedio_aritmetico_acumulado' => '90']),
    ]);

    $reader = new SiiCsvReader($this->path, SiiFile::Alumnos);

    expect(iterator_to_array($reader->rows()))->toBe([])
        ->and($reader->malformed())->toBe([2])
        ->and($reader->repaired())->toBe(0);
});

it('ignora los renglones en blanco y la anotación al final', function () {
    SiiFixtures::write($this->path, SiiFile::Alumnos, [
        SiiFixtures::line(SiiFile::Alumnos),
        '',
        "'Total de registros','1'",
    ]);

    $reader = new SiiCsvReader($this->path, SiiFile::Alumnos);

    expect(iterator_to_array($reader->rows()))->toHaveCount(1)
        ->and($reader->malformed())->toBe([]);
});

it('manda a malformados un renglón con una comilla sin cerrar', function () {
    SiiFixtures::write($this->path, SiiFile::Alumnos, [
        SiiFixtures::line(SiiFile::Alumnos),
        SiiFixtures::line(SiiFile::Alumnos, ['campus' => "'SIN CERRAR"]),
        SiiFixtures::line(SiiFile::Alumnos),
    ]);

    $reader = new SiiCsvReader($this->path, SiiFile::Alumnos);

    expect(array_keys(iterator_to_array($reader->rows())))->toBe([2, 4])
        ->and($reader->malformed())->toBe([3]);
});

it('falla con un mensaje claro si el encabezado tiene una comilla sin cerrar', function () {
    file_put_contents($this->path, "\"no_de_control,carrera\r\n");

    expect(fn () => (new SiiCsvReader($this->path, SiiFile::Alumnos))->header())
        ->toThrow(RuntimeException::class, 'encabezado');
});

it('no entrega nada si el archivo solo trae el encabezado', function () {
    SiiFixtures::write($this->path, SiiFile::Alumnos, []);

    $reader = new SiiCsvReader($this->path, SiiFile::Alumnos);

    expect(iterator_to_array($reader->rows()))->toBe([])
        ->and($reader->malformed())->toBe([]);
});

it('manda a malformados un renglón con menos campos que el encabezado', function () {
    SiiFixtures::write($this->path, SiiFile::Alumnos, [
        SiiFixtures::line(SiiFile::Alumnos),
        "'10','ISC'",
    ]);

    $reader = new SiiCsvReader($this->path, SiiFile::Alumnos);

    expect(array_keys(iterator_to_array($reader->rows())))->toBe([2])
        ->and($reader->malformed())->toBe([3]);
});

it('lee el último renglón aunque no termine en salto de línea', function () {
    SiiFixtures::write($this->path, SiiFile::Alumnos, [SiiFixtures::line(SiiFile::Alumnos, ['no_de_control' => "'77'"])]);
    file_put_contents($this->path, rtrim(file_get_contents($this->path), "\r\n"));

    $rows = iterator_to_array((new SiiCsvReader($this->path, SiiFile::Alumnos))->rows());

    expect($rows[2]['no_de_control'])->toBe('77');
});

it('lee los renglones cuando el archivo empieza con BOM', function () {
    SiiFixtures::write($this->path, SiiFile::Alumnos, [
        SiiFixtures::line(SiiFile::Alumnos, ['no_de_control' => "'1'"]),
        SiiFixtures::line(SiiFile::Alumnos, ['no_de_control' => "'2'"]),
    ]);
    file_put_contents($this->path, "\xEF\xBB\xBF".file_get_contents($this->path));

    $rows = iterator_to_array((new SiiCsvReader($this->path, SiiFile::Alumnos))->rows());

    expect(array_column($rows, 'no_de_control'))->toBe(['1', '2']);
});

it('detecta las comillas dobles', function () {
    $header = implode(',', array_map(fn ($h) => '"'.$h.'"', SiiFile::Aulas->headers()));
    file_put_contents($this->path, $header."\r\n\"A1\",\"Edificio, norte\",\"30\",\"x\",\"x\",\"x\"\r\n");

    $rows = iterator_to_array((new SiiCsvReader($this->path, SiiFile::Aulas))->rows());

    expect($rows[2]['ubicacion'])->toBe('Edificio, norte');
});

it('no repara si un texto aprendido entre comillas llega sin ellas', function () {
    $blankNumbers = [
        'promedio_periodo_anterior' => '',
        'creditos_aprobados' => '',
        'creditos_cursados' => '',
        'promedio_final_alcanzado' => '',
        'indice_reprobacion_acumulado' => '',
    ];
    SiiFixtures::write($this->path, SiiFile::Alumnos, [
        SiiFixtures::line(SiiFile::Alumnos),
        SiiFixtures::line(SiiFile::Alumnos, [...$blankNumbers, 'promedio_aritmetico_acumulado' => '87,50']),
        SiiFixtures::line(SiiFile::Alumnos, [...$blankNumbers, 'promedio_aritmetico_acumulado' => '87,50', 'escuela_procedencia' => 'sin comillas']),
    ]);

    $reader = new SiiCsvReader($this->path, SiiFile::Alumnos);
    $rows = iterator_to_array($reader->rows());

    expect(array_keys($rows))->toBe([2, 3])
        ->and($reader->repaired())->toBe(1)
        ->and($reader->malformed())->toBe([4]);
});

it('convierte varios acentos de Windows-1252 en un mismo renglón', function () {
    SiiFixtures::write($this->path, SiiFile::Alumnos, [
        SiiFixtures::line(SiiFile::Alumnos, ['escuela_procedencia' => "'\xC1 \xC9 \xCD \xD3 \xDA \xD1 \xFC'"]),
    ]);

    $rows = iterator_to_array((new SiiCsvReader($this->path, SiiFile::Alumnos))->rows());

    expect($rows[2]['escuela_procedencia'])->toBe('Á É Í Ó Ú Ñ ü');
});

it('normaliza encabezados con acentos', function () {
    file_put_contents($this->path, "'Clave Área','Descripción Ñandú'\r\n'x','y'\r\n");

    expect((new SiiCsvReader($this->path, SiiFile::Organigrama))->header())->toBe(['clave_area', 'descripcion_nandu']);
});

it('con una lista de columnas entrega solo esas y descarta las demás', function () {
    SiiFixtures::write($this->path, SiiFile::Alumnos, [
        SiiFixtures::line(SiiFile::Alumnos, ['no_de_control' => "'10'", 'carrera' => "'ISC'", 'nip' => "'secreto'", 'curp_alumno' => "'CURP'"]),
    ]);

    $rows = iterator_to_array((new SiiCsvReader($this->path, SiiFile::Alumnos))->rows(['no_de_control', 'carrera']));

    expect($rows[2])->toBe(['no_de_control' => '10', 'carrera' => 'ISC']);
});
