<?php

namespace App\Modules\Scheduling\Support;

use RuntimeException;

/**
 * Lee el csv de la «Relación de materias» (R-PRY-1): separador `;`, UTF-8 o Windows-1252 (se detecta y convierte), con
 * o sin BOM y con CRLF. Los renglones de antes del encabezado (el título del periodo) y los vacíos (`;;;;`) no salen. El
 * encabezado es el primer renglón con «CLAVE DE MATERIA»; las columnas se buscan por su nombre, sin acentos ni
 * mayúsculas, y el rango de la hora («9 - 10») es la columna sin nombre que sigue a HORA. BASE DOCENTE y CARRERA se
 * ignoran. El número de renglón cuenta los registros del csv desde 1.
 */
final class LoadRelationSheet
{
    private const string KEY_HEADER = 'CLAVE DE MATERIA';

    /**
     * Si el archivo es un `.csv` con el encabezado de la relación.
     */
    public static function isRelation(string $path): bool
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'csv' || ! is_file($path) || ! is_readable($path)) {
            return false;
        }

        return self::locateHeader(self::records($path)) !== null;
    }

    /**
     * @return list<LoadRelationRow>
     *
     * @throws RuntimeException
     */
    public static function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("No se encontró el archivo {$path}.");
        }

        $records = self::records($path);
        $header = self::locateHeader($records);

        if ($header === null) {
            throw new RuntimeException('El archivo no tiene el encabezado «CLAVE DE MATERIA»: no es la relación de materias.');
        }

        [$headerLine, $columns] = $header;
        $rows = [];

        foreach ($records as $line => $cells) {
            if ($line <= $headerLine || trim(implode('', $cells)) === '') {
                continue;
            }

            $cell = fn (string $name): string => isset($columns[$name]) ? trim((string) ($cells[$columns[$name]] ?? '')) : '';
            $letter = mb_strtoupper($cell('HORA'));
            $range = self::range($columns['RANGO'] !== null ? trim((string) ($cells[$columns['RANGO']] ?? '')) : '');
            preg_match('/^([A-Z]{2,6}-\d{4}-\d{2,4})/u', mb_strtoupper($cell('CLAVE CARRERA')), $plan);

            $rows[] = new LoadRelationRow(
                line: $line,
                teacherName: self::blank($cell('MAESTRO')),
                subjectKey: $cell('CLAVE DE MATERIA'),
                letter: self::blank($letter),
                hourRange: $range,
                semester: self::integer($cell('SEMESTRE')),
                planCode: $plan[1] ?? null,
                credits: self::integer($cell('CREDITOS')),
                roomCode: self::blank($cell('SALON')),
                students: self::integer($cell('ALUMNOS')),
                space: self::space($cell('LABORATORIO / SALON')),
                subjectName: $cell('MATERIA'),
            );
        }

        return $rows;
    }

    /**
     * Los registros del csv, por número de renglón (1 = el primero), con el archivo en UTF-8.
     *
     * @return array<int, list<string>>
     */
    private static function records(string $path): array
    {
        $content = (string) file_get_contents($path);
        $content = str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;

        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $content = str_replace("\u{00A0}", ' ', $content);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $records = [];
        $line = 0;

        while (($cells = fgetcsv($stream, null, ';', '"', '')) !== false) {
            $line++;
            $records[$line] = array_map(fn (?string $cell): string => (string) $cell, $cells);
        }

        fclose($stream);

        return $records;
    }

    /**
     * El renglón del encabezado y el índice de cada columna por su nombre normalizado.
     *
     * @param  array<int, list<string>>  $records
     * @return array{int, array<string, ?int>}|null
     */
    private static function locateHeader(array $records): ?array
    {
        foreach ($records as $line => $cells) {
            $names = array_map(self::normalize(...), $cells);
            $index = array_search(self::KEY_HEADER, $names, true);

            if ($index === false) {
                continue;
            }

            $columns = [];

            foreach ($names as $position => $name) {
                if ($name !== '' && ! isset($columns[$name])) {
                    $columns[$name] = $position;
                }
            }

            $hour = $columns['HORA'] ?? null;
            $columns['RANGO'] = $hour !== null && ($names[$hour + 1] ?? null) === '' ? $hour + 1 : null;

            return [$line, $columns];
        }

        return null;
    }

    private static function normalize(string $text): string
    {
        $text = mb_strtoupper(trim($text));

        return preg_replace('/\s+/u', ' ', strtr($text, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N'])) ?? $text;
    }

    private static function blank(string $text): ?string
    {
        return $text === '' ? null : $text;
    }

    private static function range(string $text): ?string
    {
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return $text === '' || str_starts_with($text, '#') ? null : $text;
    }

    private static function integer(string $text): ?int
    {
        return preg_match('/^\d+$/', $text) === 1 ? (int) $text : null;
    }

    /**
     * Valores que entiende la columna «LABORATORIO / SALÓN», en mayúsculas y sin acentos. En las relaciones de Sistemas
     * «LABORATORIO» es la sala de cómputo y «SALÓN» el aula; el laboratorio de verdad se escribe «LABORATORIO DE REDES»
     * (usuario, 25 de noviembre de 2026). Qué tipo de espacio da cada valor lo decide quien lo usa (Staffing, 1.41).
     */
    public const array SPACES = ['LABORATORIO', 'SALON', 'AULA', 'SALA DE COMPUTO', 'LABORATORIO DE REDES'];

    private static function space(string $text): ?string
    {
        $text = self::normalize($text);

        return in_array($text, self::SPACES, true) ? $text : null;
    }
}
