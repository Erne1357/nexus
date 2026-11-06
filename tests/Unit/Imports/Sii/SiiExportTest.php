<?php

use App\Modules\Imports\Sii\SiiContractBroken;
use App\Modules\Imports\Sii\SiiExport;
use App\Modules\Imports\Sii\SiiFile;
use Tests\Unit\Imports\Sii\SiiFixtures;

require_once __DIR__.'/SiiFixtures.php';

beforeEach(function () {
    $this->dir = SiiFixtures::dir();
});

afterEach(fn () => SiiFixtures::clean($this->dir));

/**
 * Escribe todos los archivos del SII con un renglón sintético; el nombre sale de $names o del valor del caso.
 *
 * @param  array<string, string>  $names  nombre de archivo por valor de SiiFile
 * @param  array<string, list<string>>  $headers  encabezados alternos por valor de SiiFile
 */
function writeExport(string $dir, array $names = [], array $headers = [], array $skip = []): void
{
    foreach (SiiFile::cases() as $file) {
        if (in_array($file->value, $skip, true)) {
            continue;
        }
        SiiFixtures::write(
            $dir.'/'.($names[$file->value] ?? $file->value).'.csv',
            $file,
            [SiiFixtures::line($file)],
            isset($headers[$file->value]) ? SiiFixtures::headerLine($headers[$file->value]) : null,
        );
    }
}

it('abre una exportación completa y entrega un lector por archivo', function () {
    writeExport($this->dir);

    $export = SiiExport::open($this->dir);

    expect($export->dir())->toBe($this->dir)
        ->and(iterator_to_array($export->reader(SiiFile::Aulas)->rows()))->toHaveCount(1);
});

it('encuentra los archivos sin importar mayúsculas ni espacios', function () {
    writeExport($this->dir, ['docentes' => 'Docentes', 'alumnos_todos' => 'alumnos todos', 'docentes2' => 'Docentes2']);

    $export = SiiExport::open($this->dir);

    expect($export->reader(SiiFile::Docentes)->header())->toBe(SiiFile::Docentes->headers())
        ->and($export->reader(SiiFile::Alumnos)->header())->toBe(SiiFile::Alumnos->headers());
});

it('detiene la migración si cambian los encabezados', function () {
    $headers = array_map(fn ($h) => $h === 'capacidad_aula' ? 'capacidad' : $h, SiiFile::Aulas->headers());
    writeExport($this->dir, headers: ['aulas' => $headers]);

    try {
        SiiExport::open($this->dir);
        $this->fail('Debió lanzar SiiContractBroken.');
    } catch (SiiContractBroken $e) {
        expect($e->differences['aulas'])->toBe(['missing' => ['capacidad_aula'], 'extra' => ['capacidad']])
            ->and($e->differences)->toHaveCount(1)
            ->and($e->getMessage())->toContain('aulas', 'capacidad_aula', 'capacidad');
    }
});

it('reporta un archivo obligatorio que no está en la carpeta', function () {
    writeExport($this->dir, skip: ['horarios']);

    try {
        SiiExport::open($this->dir);
        $this->fail('Debió lanzar SiiContractBroken.');
    } catch (SiiContractBroken $e) {
        expect($e->differences['horarios'])->toBe(['missing' => SiiFile::Horarios->headers(), 'extra' => []]);
    }
});

it('ignora los archivos que no son CSV', function () {
    writeExport($this->dir, skip: ['aulas']);
    file_put_contents($this->dir.'/aulas.txt', 'x');

    expect(fn () => SiiExport::open($this->dir))->toThrow(SiiContractBroken::class);
});

it('normaliza el nombre del archivo con acentos y símbolos', function () {
    expect(SiiFile::normalize('Organigrama Ñ (2).csv'))->toBe('organigrama_n_2_csv')
        ->and(SiiFile::normalize('ÁREAS técnicas'))->toBe('areas_tecnicas');
});
