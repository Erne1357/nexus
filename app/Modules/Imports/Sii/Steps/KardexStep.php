<?php

namespace App\Modules\Imports\Sii\Steps;

use App\Modules\Imports\Sii\KardexCodes;
use App\Modules\Imports\Sii\SiiBatch;
use App\Modules\Imports\Sii\SiiExport;
use App\Modules\Imports\Sii\SiiFile;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\SiiStep;
use App\Modules\Imports\Sii\SiiStepOutOfOrder;
use App\Modules\Students\Enums\KardexResult;
use Generator;

/**
 * Kárdex del SII (reglas-de-negocio.md, "Migración del kárdex del SII"). `historia_alumno` es el kárdex: cada renglón
 * pasa con la materia de Nexus (`subject_for`), su resultado, su calificación y su oportunidad (KardexCodes, K1 y
 * K2). En los periodos cerrados el kárdex sale solo de la historia (K3, confirmado el 9 de noviembre de 2026): lo que
 * la selección trae y la historia no, solo se cuenta. Las materias del periodo en curso salen de
 * `seleccion_materias` como «cursando»: el periodo en curso es el más reciente de la selección mientras menos de la
 * mitad de sus renglones estén ya en la historia, los inscritos (`status_seleccion` I), con oportunidad 2 si
 * `repeticion` es S (supuestos por confirmar). Si la historia ya trae esa materia en ese periodo, queda la de la
 * historia.
 *
 * Nexus guarda una fila por alumno, materia y periodo, y dos claves del SII pueden ser la misma materia de Nexus: de
 * los renglones repetidos queda la aprobada antes que la reprobada, luego la de mayor calificación y luego la última
 * del archivo (supuesto por confirmar). Para no tener los dos millones de renglones en memoria, la historia se lee
 * dos veces: la primera guarda, por llave, solo un entero con la línea que gana (la llave es un hash de 64 bits
 * del alumno, la materia y el periodo), y la segunda emite esas líneas.
 *
 * Se reporta por archivo y línea, sin número de control: el periodo, el estatus y la calificación que no se entienden
 * y la línea sin número de control; agregados, la materia sin equivalente en Nexus (por clave del SII), el tipo de
 * evaluación que no se conoce, los repetidos y lo que la selección no pasa. Los dos archivos son opcionales en la
 * exportación: sin historia el kárdex no se migra, y sin selección no hay materias en curso; los dos casos avisan.
 */
final class KardexStep implements SiiStep
{
    /** @var list<string> las únicas columnas de `historia_alumno` que lee este paso */
    public const array HISTORY_COLUMNS = ['periodo', 'no_de_control', 'materia', 'calificacion', 'tipo_evaluacion', 'estatus_materia'];

    /** @var list<string> las únicas columnas de `seleccion_materias` que lee este paso */
    public const array SELECTION_COLUMNS = ['periodo', 'no_de_control', 'materia', 'repeticion', 'status_seleccion'];

    /** Bits de la línea en el valor que gana; encima van si es aprobada (128) y la calificación (0 a 100). */
    private const int LINE_BITS = 40;

    private const int LINE_MASK = (1 << self::LINE_BITS) - 1;

    private const int APPROVED_RANK = 128;

    public function name(): string
    {
        return 'kardex';
    }

    public function batches(SiiMigration $migration): iterable
    {
        /** @var array<string|int, string> $subjectFor */
        $subjectFor = $migration->remember('subject_for', fn () => throw new SiiStepOutOfOrder($this->name(), 'subject_for', ['materias']));
        $export = $migration->export();

        if (! $export->has(SiiFile::HistoriaAlumno)) {
            yield new SiiBatch('kardex', 'kardex.csv', [], incidents: [$this->warning('kardex', 'La exportación no trae historia_alumno: el kárdex no se migra.')]);

            return;
        }

        $tally = ['lines' => [], 'subjects' => [], 'types' => [], 'repeated' => 0];
        $best = $this->rank($export, $subjectFor, $tally);
        [$current, $selectionIncidents] = $this->current($export, $subjectFor, $best);

        yield new SiiBatch(
            'kardex',
            'kardex.csv',
            $this->rows($export, $subjectFor, $best, $current),
            incidents: [...$this->historyIncidents($tally), ...$selectionIncidents],
        );
    }

    /**
     * Primera pasada por la historia: por llave, el valor de la línea que gana (si es aprobada, la calificación y la
     * línea, en un entero que se compara de una vez).
     *
     * @param  array<string|int, string>  $subjectFor
     * @param  array{lines: list<array{level: 'warning', ref: string, message: string}>, subjects: array<string|int, int>, types: array<string, int>, repeated: int}  $tally
     * @return array<int, int>
     */
    private function rank(SiiExport $export, array $subjectFor, array &$tally): array
    {
        $best = [];

        foreach ($this->history($export, $subjectFor, $tally) as $line => $entry) {
            $value = ($entry['rank'] << self::LINE_BITS) | $line;

            if (isset($best[$entry['key']])) {
                $tally['repeated']++;

                if ($value < $best[$entry['key']]) {
                    continue;
                }
            }

            $best[$entry['key']] = $value;
        }

        return $best;
    }

    /**
     * Los renglones de la historia que pasan al kárdex, ya en los campos de la plantilla. Con `$tally` cuenta y anota
     * lo que no se entiende (solo en la primera pasada).
     *
     * @param  array<string|int, string>  $subjectFor
     * @param  array{lines: list<array{level: 'warning', ref: string, message: string}>, subjects: array<string|int, int>, types: array<string, int>, repeated: int}|null  $tally
     * @return Generator<int, array{key: int, rank: int, row: array<string, string|int>}>
     */
    private function history(SiiExport $export, array $subjectFor, ?array &$tally = null): Generator
    {
        $file = $export->fileName(SiiFile::HistoriaAlumno);

        foreach ($export->reader(SiiFile::HistoriaAlumno)->rows(self::HISTORY_COLUMNS) as $line => $row) {
            $ref = "{$file}:{$line}";
            $result = KardexCodes::result($row['estatus_materia']);
            $skip = match (true) {
                $row['no_de_control'] === '' => 'Esta línea no trae número de control: no pasa al kárdex.',
                ! KardexCodes::term($row['periodo']) => 'El periodo de esta línea no es un periodo del SII (año desde '.KardexCodes::FIRST_YEAR.' y 1, 2 o 3): no pasa al kárdex.',
                $result === null => 'El estatus de la materia de esta línea no es A, R ni P: no pasa al kárdex.',
                default => null,
            };

            if ($skip !== null || $result === null) {
                if ($tally !== null && $skip !== null) {
                    $tally['lines'][] = $this->warning($ref, $skip);
                }

                continue;
            }

            $subject = $subjectFor[$row['materia']] ?? null;

            if ($subject === null) {
                if ($tally !== null) {
                    $tally['subjects'][$row['materia']] = ($tally['subjects'][$row['materia']] ?? 0) + 1;
                }

                continue;
            }

            $attempt = KardexCodes::attempt($row['tipo_evaluacion']);

            if ($tally !== null) {
                if (! KardexCodes::readableGrade($row['calificacion'])) {
                    $tally['lines'][] = $this->warning($ref, 'La calificación de esta línea no se entiende: pasa al kárdex sin calificación.');
                }

                if ($attempt === null) {
                    $type = strtoupper($row['tipo_evaluacion']);
                    $tally['types'][$type] = ($tally['types'][$type] ?? 0) + 1;
                }
            }

            $grade = KardexCodes::grade($row['calificacion'], $result);

            yield $line => [
                'key' => self::key($row['no_de_control'], $subject, $row['periodo']),
                'rank' => ($result === KardexResult::Aprobada ? self::APPROVED_RANK : 0) | ($grade ?? 0),
                'row' => [
                    'control_number' => $row['no_de_control'],
                    'subject_code' => $subject,
                    'term_code' => $row['periodo'],
                    'grade' => $grade ?? '',
                    'result' => $result->value,
                    'attempt' => $attempt ?? 1,
                ],
            ];
        }
    }

    /**
     * Las materias del periodo en curso, como «cursando», y los avisos de la selección.
     *
     * @param  array<string|int, string>  $subjectFor
     * @param  array<int, int>  $best
     * @return array{0: list<array<string, string|int>>, 1: list<array{level: 'warning', ref: string, message: string}>}
     */
    private function current(SiiExport $export, array $subjectFor, array $best): array
    {
        if (! $export->has(SiiFile::SeleccionMaterias)) {
            return [[], [$this->warning('kardex', 'La exportación no trae seleccion_materias: ninguna materia del periodo en curso pasa como cursando.')]];
        }

        $latest = null;
        /** @var array<int, array<string, string|int>> $candidates del periodo más reciente visto, por llave */
        $candidates = [];
        /** @var array<string, array{history: int, missing: int, notEnrolled: int, repeated: int, subjects: array<string|int, int>}> $terms */
        $terms = [];

        foreach ($export->reader(SiiFile::SeleccionMaterias)->rows(self::SELECTION_COLUMNS) as $row) {
            $term = $row['periodo'];

            if (! KardexCodes::term($term) || $row['no_de_control'] === '') {
                continue;
            }

            if ($latest === null || strcmp($term, $latest) > 0) {
                $latest = $term;
                $candidates = [];
            }

            $terms[$term] ??= ['history' => 0, 'missing' => 0, 'notEnrolled' => 0, 'repeated' => 0, 'subjects' => []];
            $subject = $subjectFor[$row['materia']] ?? null;

            if ($subject === null) {
                $terms[$term]['subjects'][$row['materia']] = ($terms[$term]['subjects'][$row['materia']] ?? 0) + 1;

                continue;
            }

            $key = self::key($row['no_de_control'], $subject, $term);

            if (isset($best[$key])) {
                $terms[$term]['history']++;

                continue;
            }

            $terms[$term]['missing']++;

            if ($term !== $latest) {
                continue;
            }

            if (strtoupper($row['status_seleccion']) !== 'I') {
                $terms[$term]['notEnrolled']++;
            } elseif (isset($candidates[$key])) {
                $terms[$term]['repeated']++;
            } else {
                $candidates[$key] = [
                    'control_number' => $row['no_de_control'],
                    'subject_code' => $subject,
                    'term_code' => $term,
                    'grade' => '',
                    'result' => KardexResult::Cursando->value,
                    'attempt' => strtoupper($row['repeticion']) === 'S' ? 2 : 1,
                ];
            }
        }

        $open = $latest !== null && $terms[$latest]['history'] * 2 < $terms[$latest]['history'] + $terms[$latest]['missing'];
        $closedMissing = array_sum(array_column(array_diff_key($terms, $open ? [$latest => true] : []), 'missing'));
        $incidents = [];

        if ($closedMissing > 0) {
            $incidents[] = $this->warning('kardex', "{$closedMissing} renglones de seleccion_materias de periodos cerrados no están en la historia: no pasan al kárdex, que en esos periodos sale solo de la historia.");
        }

        if ($latest === null) {
            return [[], $incidents];
        }

        if (! $open) {
            $incidents[] = $this->warning('kardex', "El periodo más reciente de seleccion_materias ({$latest}) ya está en la historia: ninguna materia pasa como cursando.");

            return [[], $incidents];
        }

        $stats = $terms[$latest];
        $subjects = $stats['subjects'];
        ksort($subjects, SORT_STRING);

        foreach ($subjects as $materia => $count) {
            $incidents[] = $this->warning((string) $materia, "La materia {$materia} del SII no tiene equivalente en Nexus: {$count} renglones de la selección de {$latest} no pasan al kárdex.");
        }

        foreach ([
            [$stats['notEnrolled'], 'no están inscritos (status_seleccion distinto de I): no pasan al kárdex.'],
            [$stats['history'], 'ya están en la historia: queda el de la historia.'],
            [$stats['repeated'], 'repiten alumno, materia de Nexus y periodo: pasa uno.'],
        ] as [$count, $text]) {
            if ($count > 0) {
                $incidents[] = $this->warning('kardex', "{$count} renglones de la selección de {$latest} {$text}");
            }
        }

        return [array_values($candidates), $incidents];
    }

    /**
     * Segunda pasada: las líneas de la historia que ganaron su llave y, al final, las materias en curso.
     *
     * @param  array<string|int, string>  $subjectFor
     * @param  array<int, int>  $best
     * @param  list<array<string, string|int>>  $current
     * @return Generator<int, array<string, string|int>>
     */
    private function rows(SiiExport $export, array $subjectFor, array $best, array $current): Generator
    {
        foreach ($this->history($export, $subjectFor) as $line => $entry) {
            if (($best[$entry['key']] & self::LINE_MASK) === $line) {
                yield $entry['row'];
            }
        }

        foreach ($current as $row) {
            yield $row;
        }
    }

    /**
     * @param  array{lines: list<array{level: 'warning', ref: string, message: string}>, subjects: array<string|int, int>, types: array<string, int>, repeated: int}  $tally
     * @return list<array{level: 'warning', ref: string, message: string}>
     */
    private function historyIncidents(array $tally): array
    {
        $incidents = $tally['lines'];
        $subjects = $tally['subjects'];
        ksort($subjects, SORT_STRING);

        foreach ($subjects as $materia => $count) {
            $incidents[] = $this->warning((string) $materia, "La materia {$materia} del SII no tiene equivalente en Nexus: {$count} renglones de la historia no pasan al kárdex.");
        }

        $types = $tally['types'];
        ksort($types, SORT_STRING);

        foreach ($types as $type => $count) {
            $incidents[] = $this->warning('kardex', $type === ''
                ? "{$count} renglones de la historia no traen tipo de evaluación: cuentan como oportunidad 1."
                : "{$count} renglones de la historia traen el tipo de evaluación «{$type}», que no se conoce: cuentan como oportunidad 1.");
        }

        if ($tally['repeated'] > 0) {
            $incidents[] = $this->warning('kardex', "{$tally['repeated']} renglones de la historia repiten alumno, materia de Nexus y periodo: queda uno, la aprobada antes que la reprobada, luego la de mayor calificación y luego la última del archivo.");
        }

        return $incidents;
    }

    /**
     * La llave de un renglón del kárdex: un hash de 64 bits del alumno, la materia de Nexus y el periodo. Con unos
     * dos millones de llaves, la probabilidad de que dos choquen es de una en millones.
     */
    private static function key(string $controlNumber, string $subject, string $term): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('q', hash('xxh3', "{$controlNumber}|{$subject}|{$term}", true));

        return $unpacked[1];
    }

    /**
     * @return array{level: 'warning', ref: string, message: string}
     */
    private function warning(string $ref, string $message): array
    {
        return ['level' => 'warning', 'ref' => $ref, 'message' => $message];
    }
}
