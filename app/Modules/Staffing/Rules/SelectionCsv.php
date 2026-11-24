<?php

namespace App\Modules\Staffing\Rules;

/**
 * SEL-06. Lee el CSV de criterios de la selección (separado por `;`, con o sin BOM): nombre completo, horas de la
 * plaza, autoevaluación, cumplimiento de asignación previa, parciales C1 y C2 y antigüedad en años (coma o punto).
 * El encabezado se lee sin importar mayúsculas ni acentos. Un renglón inválido va a `errors` con su línea (1 es el
 * encabezado). Puro.
 */
final readonly class SelectionCsv
{
    private const COLUMNS = [
        'name' => 'NOMBRE COMPLETO',
        'hours' => 'HORAS',
        'auto_eval' => 'AUTO EV',
        'prior_compliance' => 'CUMPL ASIG',
        'partials_1' => 'C1',
        'partials_2' => 'C2',
        'seniority_years' => 'ANTIGUEDAD',
    ];

    /**
     * @return array{rows: list<array{line: int, name: string, hours: int, auto_eval: bool, prior_compliance: bool, partials_1: bool, partials_2: bool, seniority_years: float}>, errors: list<array{line: int, message: string}>}
     */
    public static function parse(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        // El «CSV» de Excel en español es Windows-1252, no UTF-8.
        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        $rows = [];
        $errors = [];
        $index = null;

        foreach ($lines as $i => $raw) {
            $line = $i + 1;

            if (trim($raw) === '') {
                continue;
            }

            $cells = str_getcsv($raw, ';', '"', '');

            if ($index === null) {
                $headers = array_map(fn (?string $h): string => TeacherNameMatcher::normalize((string) $h), $cells);
                $index = [];

                foreach (self::COLUMNS as $field => $header) {
                    $position = array_search($header, $headers, true);

                    if ($position === false) {
                        return ['rows' => [], 'errors' => [['line' => $line, 'message' => "Falta la columna «{$header}»."]]];
                    }

                    $index[$field] = $position;
                }

                continue;
            }

            $cell = fn (string $field): string => trim((string) ($cells[$index[$field]] ?? ''));
            $name = $cell('name');
            $hours = $cell('hours');
            $seniority = str_replace(',', '.', $cell('seniority_years'));

            if ($name === '') {
                $errors[] = ['line' => $line, 'message' => 'Falta el nombre.'];
            } elseif (preg_match('/^\d+$/D', $hours) !== 1) {
                $errors[] = ['line' => $line, 'message' => "Las horas «{$hours}» no son un número entero."];
            } elseif (! is_numeric($seniority) || (float) $seniority < 0) {
                $errors[] = ['line' => $line, 'message' => "La antigüedad «{$cell('seniority_years')}» no es un número."];
            } else {
                $flags = [];

                foreach (['auto_eval', 'prior_compliance', 'partials_1', 'partials_2'] as $field) {
                    $flags[$field] = self::flag($cell($field));

                    if ($flags[$field] === null) {
                        $errors[] = ['line' => $line, 'message' => "Línea {$line}: \"{$cell($field)}\" no es sí ni no en la columna ".self::COLUMNS[$field]];

                        continue 2;
                    }
                }

                $rows[] = [
                    'line' => $line,
                    'name' => $name,
                    'hours' => (int) $hours,
                    ...$flags,
                    'seniority_years' => (float) $seniority,
                ];
            }
        }

        if ($rows === [] && $errors === []) {
            $errors[] = ['line' => 1, 'message' => 'El archivo no trae docentes.'];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * Verdadero: sí, 1, x. Falso: no, 0 o vacío. Cualquier otro valor es nulo (error).
     */
    private static function flag(string $value): ?bool
    {
        $value = TeacherNameMatcher::normalize($value);

        return match (true) {
            in_array($value, ['SI', '1', 'X'], true) => true,
            in_array($value, ['NO', '0', ''], true) => false,
            default => null,
        };
    }
}
