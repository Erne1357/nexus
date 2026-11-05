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
