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
                $rows[] = [
                    'line' => $line,
                    'name' => $name,
                    'hours' => (int) $hours,
                    'auto_eval' => self::flag($cell('auto_eval')),
                    'prior_compliance' => self::flag($cell('prior_compliance')),
                    'partials_1' => self::flag($cell('partials_1')),
                    'partials_2' => self::flag($cell('partials_2')),
                    'seniority_years' => (float) $seniority,
                ];
            }
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    private static function flag(string $value): bool
    {
        return in_array(TeacherNameMatcher::normalize($value), ['SI', '1', 'X'], true);
    }
}
