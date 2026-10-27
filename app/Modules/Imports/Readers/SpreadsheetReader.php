<?php

namespace App\Modules\Imports\Readers;

use Generator;
use Illuminate\Support\LazyCollection;
use OpenSpout\Common\Exception\OpenSpoutException;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Lee la primera hoja de un CSV o XLSX en streaming con openspout (ADR-0012): nunca carga el archivo
 * completo, porque un kardex puede tener cientos de miles de filas. La primera fila es el encabezado.
 *
 * Lo que el SII entrega varía, así que en un CSV detecta el separador (coma, punto y coma o
 * tabulador) por el encabezado y la codificación (UTF-8 o Windows-1252) por el inicio del archivo.
 * Un XLSX conserva números y fechas como tales.
 */
final class SpreadsheetReader
{
    /**
     * Bytes del inicio del archivo con los que se detectan separador y codificación.
     */
    private const int SAMPLE_BYTES = 65536;

    /**
     * Encabezado recortado, con "columna N" en las celdas vacías.
     *
     * @return list<string>
     *
     * @throws UnreadableFile
     */
    public function header(string $path): array
    {
        foreach ($this->sheetRows($path) as $cells) {
            return $this->headerFrom($cells);
        }

        throw new UnreadableFile('El archivo está vacío: no tiene encabezado.');
    }

    /**
     * Filas de datos por número de fila en el archivo (el encabezado es la 1), como encabezado =>
     * valor. Una fila corta se completa con null, lo que sobra a la derecha del encabezado se ignora y
     * una fila vacía se salta sin perder la numeración.
     *
     * @return LazyCollection<int, array<string, mixed>>
     *
     * @throws UnreadableFile al recorrerla
     */
    public function rows(string $path): LazyCollection
    {
        return LazyCollection::make(function () use ($path): Generator {
            $header = null;

            foreach ($this->sheetRows($path) as $number => $cells) {
                if ($header === null) {
                    $header = $this->headerFrom($cells);

                    continue;
                }

                if ($this->isEmpty($cells)) {
                    continue;
                }

                $values = [];

                foreach ($header as $index => $name) {
                    $values[$name] = $cells[$index] ?? null;
                }

                yield $number => $values;
            }

            if ($header === null) {
                throw new UnreadableFile('El archivo está vacío: no tiene encabezado.');
            }
        });
    }

    /**
     * Celdas de cada fila de la primera hoja, con su número de fila en el archivo.
     *
     * @return Generator<int, list<mixed>>
     */
    private function sheetRows(string $path): Generator
    {
        $reader = $this->readerFor($path);

        try {
            $reader->open($path);

            foreach ($reader->getSheetIterator() as $sheet) {
                $number = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    yield ++$number => $row->toArray();
                }

                return;
            }
        } catch (OpenSpoutException $exception) {
            throw new UnreadableFile('No se pudo leer el archivo: '.$exception->getMessage(), previous: $exception);
        } finally {
            $reader->close();
        }
    }

    private function readerFor(string $path): ReaderInterface
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'csv', 'txt' => new CsvReader($this->csvOptions($path)),
            // Las filas vacías se conservan para que la numeración sea la del archivo; rows() las salta.
            'xlsx' => new XlsxReader(new XlsxOptions(SHOULD_PRESERVE_EMPTY_ROWS: true)),
            default => throw new UnreadableFile('Guarda el archivo como .xlsx o .csv para importarlo.'),
        };
    }

    private function csvOptions(string $path): CsvOptions
    {
        $sample = (string) file_get_contents($path, length: self::SAMPLE_BYTES);
        $firstLine = strtok($sample, "\r\n") ?: '';

        $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);

        // Si la muestra corta un carácter de varios bytes al final, solo se revisa hasta el último salto de línea.
        $lastBreak = strrpos($sample, "\n");
        $complete = strlen($sample) === self::SAMPLE_BYTES && $lastBreak !== false ? substr($sample, 0, $lastBreak) : $sample;

        return new CsvOptions(
            SHOULD_PRESERVE_EMPTY_ROWS: true,
            FIELD_DELIMITER: max($counts) > 0 ? array_key_first($counts) : ',',
            ENCODING: mb_check_encoding($complete, 'UTF-8') ? 'UTF-8' : 'Windows-1252',
        );
    }

    /**
     * @param  list<mixed>  $cells
     * @return list<string>
     *
     * @throws UnreadableFile si una columna se repite
     */
    private function headerFrom(array $cells): array
    {
        $header = [];

        foreach ($cells as $index => $cell) {
            $name = trim((string) $cell);
            $name = $name === '' ? 'columna '.($index + 1) : $name;

            if (in_array($name, $header, true)) {
                throw new UnreadableFile("La columna «{$name}» se repite en el encabezado.");
            }

            $header[] = $name;
        }

        if ($header === []) {
            throw new UnreadableFile('El archivo está vacío: no tiene encabezado.');
        }

        return $header;
    }

    /**
     * @param  list<mixed>  $cells
     */
    private function isEmpty(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== null && $cell !== '') {
                return false;
            }
        }

        return true;
    }
}
