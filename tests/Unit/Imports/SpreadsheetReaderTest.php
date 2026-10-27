<?php

use App\Modules\Imports\Readers\SpreadsheetReader;
use App\Modules\Imports\Readers\UnreadableFile;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Escribe un archivo temporal con el contenido dado y devuelve su ruta; se borra al terminar la prueba.
 */
function spreadsheetFile(string $extension, string $contents): string
{
    $path = sys_get_temp_dir().'/'.uniqid('nexus-import-', true).'.'.$extension;
    file_put_contents($path, $contents);
    register_shutdown_function(fn () => @unlink($path));

    return $path;
}

/**
 * Un XLSX con las filas dadas; las fechas llevan formato de fecha, como en un Excel del SII.
 *
 * @param  list<list<mixed>>  $rows
 */
function xlsxFile(array $rows): string
{
    $path = spreadsheetFile('xlsx', '');
    $writer = new Writer;
    $writer->openToFile($path);
    $dateStyle = (new Style)->withFormat('yyyy-mm-dd');

    foreach ($rows as $row) {
        $writer->addRow(new Row(array_map(
            fn (mixed $value): Cell => Cell::fromValue($value, $value instanceof DateTimeInterface ? $dateStyle : null),
            $row,
        )));
    }

    $writer->close();

    return $path;
}

it('lee un CSV fila por fila con el encabezado como llaves y el número de fila del archivo', function () {
    $path = spreadsheetFile('csv', "clave,nombre\nISC,Sistemas\nIGE,Gestión\n");

    expect((new SpreadsheetReader)->rows($path)->all())->toBe([
        2 => ['clave' => 'ISC', 'nombre' => 'Sistemas'],
        3 => ['clave' => 'IGE', 'nombre' => 'Gestión'],
    ]);
});

it('detecta el separador de punto y coma o tabulador por el encabezado', function (string $separator) {
    $path = spreadsheetFile('csv', "clave{$separator}nombre\nISC{$separator}Sistemas, Computacionales\n");

    expect((new SpreadsheetReader)->rows($path)->all())
        ->toBe([2 => ['clave' => 'ISC', 'nombre' => 'Sistemas, Computacionales']]);
})->with(['punto y coma' => ';', 'tabulador' => "\t"]);

it('lee un CSV en Windows-1252 como texto UTF-8', function () {
    $path = spreadsheetFile('csv', mb_convert_encoding("clave,nombre\nIGE,Gestión Empresarial\n", 'Windows-1252', 'UTF-8'));

    expect((new SpreadsheetReader)->rows($path)->first())->toBe(['clave' => 'IGE', 'nombre' => 'Gestión Empresarial']);
});

it('quita el BOM de UTF-8 del primer encabezado', function () {
    $path = spreadsheetFile('csv', "\xEF\xBB\xBFclave,nombre\nISC,Sistemas\n");

    expect((new SpreadsheetReader)->header($path))->toBe(['clave', 'nombre']);
});

it('recorta los encabezados y nombra los vacíos por su número de columna', function () {
    $path = spreadsheetFile('csv', " clave ,,nombre\nISC,x,Sistemas\n");

    expect((new SpreadsheetReader)->header($path))->toBe(['clave', 'columna 2', 'nombre']);
});

it('completa con null las celdas que faltan, ignora las que sobran y salta las filas vacías', function () {
    $path = spreadsheetFile('csv', "clave,nombre\nISC\n\nIGE,Gestión,extra\n");

    expect((new SpreadsheetReader)->rows($path)->all())->toBe([
        2 => ['clave' => 'ISC', 'nombre' => null],
        4 => ['clave' => 'IGE', 'nombre' => 'Gestión'],
    ]);
});

it('lee la primera hoja de un XLSX conservando números y fechas', function () {
    $date = new DateTimeImmutable('2027-01-15 00:00:00');
    $path = xlsxFile([['clave', 'creditos', 'inicio'], ['ISC', 5, $date]]);

    $row = (new SpreadsheetReader)->rows($path)->first();

    expect($row['clave'])->toBe('ISC')
        ->and($row['creditos'])->toBe(5)
        ->and($row['inicio'])->toBeInstanceOf(DateTimeImmutable::class);
});

it('no carga el archivo completo: entrega las filas conforme se piden', function () {
    $path = spreadsheetFile('csv', "clave\n".implode("\n", range(1, 5000))."\n");

    $rows = (new SpreadsheetReader)->rows($path);

    expect($rows->take(2)->all())->toBe([2 => ['clave' => '1'], 3 => ['clave' => '2']])
        ->and($rows->count())->toBe(5000);
});

it('rechaza con un mensaje en español lo que no puede leer', function (string $path, string $message) {
    expect(fn () => (new SpreadsheetReader)->header($path))->toThrow(UnreadableFile::class, $message);
})->with([
    'Excel antiguo' => [fn () => spreadsheetFile('xls', 'x'), 'Guarda el archivo como .xlsx o .csv para importarlo.'],
    'sin encabezado' => [fn () => spreadsheetFile('csv', ''), 'El archivo está vacío: no tiene encabezado.'],
    'encabezado repetido' => [fn () => spreadsheetFile('csv', "clave,clave\nA,B\n"), 'La columna «clave» se repite en el encabezado.'],
    'archivo que ya no existe' => [fn () => sys_get_temp_dir().'/no-existe.csv', 'No se encontró el archivo que se subió.'],
]);

it('detecta Windows-1252 aunque el primer acento aparezca después de los primeros 64 KB', function () {
    $ascii = str_repeat("ISC,Sistemas\n", 7000);
    $path = spreadsheetFile('csv', "clave,nombre\n".$ascii.mb_convert_encoding("IGE,Núñez\n", 'Windows-1252', 'UTF-8'));

    expect((new SpreadsheetReader)->rows($path)->last())->toBe(['clave' => 'IGE', 'nombre' => 'Núñez']);
});

it('no confunde un UTF-8 válido con Windows-1252 cuando un acento cae en el borde de un bloque', function () {
    // "code," mide 5 bytes: el bloque de 64 KB termina a la mitad de una "ó" (2 bytes).
    $long = str_repeat('ó', 40000);
    $path = spreadsheetFile('csv', "code,{$long}\nISC,x\n");

    expect((new SpreadsheetReader)->header($path))->toBe(['code', $long]);
});

it('lee el texto Unicode de Excel (UTF-16 con BOM, separado por tabuladores)', function () {
    $path = spreadsheetFile('txt', "\xFF\xFE".mb_convert_encoding("clave\tnombre\nIGE\tGestión\n", 'UTF-16LE', 'UTF-8'));

    expect((new SpreadsheetReader)->rows($path)->all())->toBe([2 => ['clave' => 'IGE', 'nombre' => 'Gestión']]);
});

it('toma el encabezado y el separador de la primera fila que no está vacía', function () {
    $path = spreadsheetFile('csv', "\n\nclave;nombre\nISC;Sistemas\n");

    expect((new SpreadsheetReader)->rows($path)->all())->toBe([4 => ['clave' => 'ISC', 'nombre' => 'Sistemas']]);
});

it('salta las filas que solo tienen espacios', function () {
    $path = spreadsheetFile('csv', "clave,nombre\n , \nISC,Sistemas\n");

    expect((new SpreadsheetReader)->rows($path)->all())->toBe([3 => ['clave' => 'ISC', 'nombre' => 'Sistemas']]);
});

it('convierte a texto una fecha en el encabezado de un XLSX', function () {
    $path = xlsxFile([['clave', new DateTimeImmutable('2027-01-15')], ['ISC', 'x']]);

    expect((new SpreadsheetReader)->header($path))->toBe(['clave', '2027-01-15']);
});
