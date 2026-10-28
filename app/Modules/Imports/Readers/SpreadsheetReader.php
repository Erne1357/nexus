<?php

namespace App\Modules\Imports\Readers;

use DateTimeInterface;
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
 * completo, porque un kardex puede tener cientos de miles de filas. La primera fila que no está
 * vacía es el encabezado.
 *
 * Lo que el SII entrega varía, así que en un CSV detecta:
 *
 * - la codificación: UTF-16 por su BOM (el "Texto Unicode" de Excel); si no, UTF-8 si todo el archivo
 *   lo es, o Windows-1252. Se revisa el archivo completo por bloques, porque el primer acento de un
 *   kardex puede estar muy abajo;
 * - el separador (coma, punto y coma o tabulador) por la primera fila que no está vacía.
 *
 * Un XLSX conserva números y fechas como tales. La detección se guarda por archivo en la instancia,
 * para que header() y rows() no recorran dos veces el mismo archivo para lo mismo.
 */
final class SpreadsheetReader
{
    /**
     * Extensiones que sabe leer; StoreImportBatch rechaza las demás al subir.
     */
    public const array EXTENSIONS = ['csv', 'txt', 'xlsx'];

    /**
     * Bytes por bloque al revisar la codificación, y de muestra para el separador.
     */
    private const int CHUNK_BYTES = 65536;

    /**
     * @var array<string, CsvOptions>
     */
    private array $csvOptions = [];

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
            if (! $this->isEmpty($cells)) {
                return $this->headerFrom($cells);
            }
        }

        throw new UnreadableFile('El archivo está vacío: no tiene encabezado.');
    }

    /**
     * Filas de datos por número de fila en el archivo, como encabezado => valor. Una fila corta se
     * completa con null, lo que sobra a la derecha del encabezado se ignora y una fila vacía (o solo
     * con espacios) se salta sin perder la numeración.
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
                if ($this->isEmpty($cells)) {
                    continue;
                }

                if ($header === null) {
                    $header = $this->headerFrom($cells);

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
        if (! is_file($path) || ! is_readable($path)) {
            throw new UnreadableFile('No se encontró el archivo que se subió.');
        }

        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'csv', 'txt' => new CsvReader($this->csvOptions[$path] ??= $this->detectCsvOptions($path)),
            // Las filas vacías se conservan para que la numeración sea la del archivo; rows() las salta.
            'xlsx' => new XlsxReader(new XlsxOptions(SHOULD_PRESERVE_EMPTY_ROWS: true)),
            default => throw new UnreadableFile('Guarda el archivo como .xlsx o .csv para importarlo.'),
        };
    }

    private function detectCsvOptions(string $path): CsvOptions
    {
        $sample = (string) file_get_contents($path, length: self::CHUNK_BYTES);

        $encoding = match (true) {
            str_starts_with($sample, "\xFF\xFE") => 'UTF-16LE',
            str_starts_with($sample, "\xFE\xFF") => 'UTF-16BE',
            $this->isUtf8($path) => 'UTF-8',
            default => 'Windows-1252',
        };

        if (str_starts_with($encoding, 'UTF-16')) {
            // Sin el BOM y en un número par de bytes, para no cortar un carácter de dos bytes.
            $body = substr($sample, 2);
            $sample = mb_convert_encoding(substr($body, 0, strlen($body) - strlen($body) % 2), 'UTF-8', $encoding);
        }

        return new CsvOptions(
            SHOULD_PRESERVE_EMPTY_ROWS: true,
            FIELD_DELIMITER: $this->delimiterOf($sample),
            ENCODING: $encoding,
        );
    }

    /**
     * Si todo el archivo es UTF-8 válido, leído por bloques. Los bytes de un carácter que queda
     * cortado al final de un bloque pasan al siguiente.
     */
    private function isUtf8(string $path): bool
    {
        $handle = fopen($path, 'rb');
        $carry = '';

        try {
            while (! feof($handle)) {
                $chunk = $carry.fread($handle, self::CHUNK_BYTES);
                $complete = $this->completeLength($chunk);
                $carry = substr($chunk, $complete);

                if (! mb_check_encoding(substr($chunk, 0, $complete), 'UTF-8')) {
                    return false;
                }
            }

            return mb_check_encoding($carry, 'UTF-8');
        } finally {
            fclose($handle);
        }
    }

    /**
     * Largo del bloque sin el carácter de varios bytes que pueda quedar incompleto al final.
     */
    private function completeLength(string $chunk): int
    {
        $length = strlen($chunk);

        for ($back = 1; $back <= min(3, $length); $back++) {
            $byte = ord($chunk[$length - $back]);

            if (($byte & 0xC0) === 0x80) {
                continue;
            }

            $needed = match (true) {
                $byte >= 0xF0 => 4,
                $byte >= 0xE0 => 3,
                $byte >= 0xC0 => 2,
                default => 1,
            };

            return $needed > $back ? $length - $back : $length;
        }

        return $length;
    }

    /**
     * Coma, punto y coma o tabulador: el que más aparece en la primera fila que no está vacía.
     */
    private function delimiterOf(string $sample): string
    {
        $firstLine = '';

        foreach (preg_split('/\r\n|\r|\n/', $sample) ?: [] as $line) {
            if (trim($line) !== '') {
                $firstLine = $line;

                break;
            }
        }

        $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);

        return max($counts) > 0 ? array_key_first($counts) : ',';
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
            $name = trim(match (true) {
                $cell instanceof DateTimeInterface => $cell->format('Y-m-d'),
                is_scalar($cell) => (string) $cell,
                default => '',
            });
            $name = $name === '' ? 'columna '.($index + 1) : $name;

            if (in_array($name, $header, true)) {
                throw new UnreadableFile("La columna «{$name}» se repite en el encabezado.");
            }

            $header[] = $name;
        }

        return $header;
    }

    /**
     * @param  list<mixed>  $cells
     */
    private function isEmpty(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== null && (! is_string($cell) || trim($cell) !== '')) {
                return false;
            }
        }

        return true;
    }
}
