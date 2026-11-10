<?php

namespace App\Modules\Imports\Sii\Steps;

use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Imports\Sii\KardexCodes;
use App\Modules\Imports\Sii\KardexSubjects;
use App\Modules\Imports\Sii\SiiBatch;
use App\Modules\Imports\Sii\SiiExport;
use App\Modules\Imports\Sii\SiiFile;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\SiiStep;
use App\Modules\Imports\Sii\SiiStepOutOfOrder;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Models\Student;
use Closure;
use Generator;
use RuntimeException;

/**
 * Kárdex del SII (reglas-de-negocio.md, "Migración del kárdex del SII"). `historia_alumno` es el kárdex: cada renglón
 * de un alumno que ya está en Nexus pasa con la materia de su retícula (`subject_for_plan` con el plan del alumno; si
 * su plan no tiene esa clave del SII, el alias general `subject_for`), la clave original del SII, su resultado, su
 * calificación y su oportunidad (KardexCodes, K1 y K2). En los periodos cerrados el kárdex sale solo de la historia (K3, confirmado el
 * 9 de noviembre de 2026): lo que la selección trae y la historia no, solo se cuenta. Un periodo de
 * `seleccion_materias` que todavía no pasó a la historia (menos de la mitad de sus inscritos están en ella) está en
 * curso, y sus inscritos (`status_seleccion` I) pasan como «cursando», con oportunidad 2 si `repeticion` es S;
 * puede haber dos, el que corre y el siguiente ya seleccionado (supuestos por confirmar). Si la historia ya trae esa
 * materia en ese periodo, queda la de la historia.
 *
 * Nexus guarda una fila por alumno, materia y periodo, y dos claves del SII pueden ser la misma materia de Nexus: de
 * los renglones repetidos queda la aprobada antes que la reprobada, luego la de mayor calificación y luego la última
 * del archivo (supuesto por confirmar). Para no tener los dos millones de renglones en memoria, la historia se lee
 * dos veces: la primera guarda, por llave (un hash de 64 bits del alumno, la materia y el periodo), un entero con
 * la línea que gana, y la segunda emite esas líneas. El entero lleva además una huella de 15 bits de la llave:
 * si dos llaves distintas chocan, el paso se detiene en vez de perder un renglón.
 *
 * Se reporta por archivo y línea, sin número de control: el periodo, el estatus y la calificación que no se entienden
 * y la línea sin número de control; agregados, los renglones de alumnos que no están en Nexus, la materia sin
 * equivalente en Nexus (por clave del SII), el tipo de evaluación que no se conoce, los repetidos y lo que la
 * selección no pasa. Los dos archivos son opcionales en la exportación: sin historia el kárdex no se migra, y sin
 * selección no hay materias en curso; los dos casos avisan.
 */
final class KardexStep implements SiiStep
{
    /** @var list<string> las únicas columnas de `historia_alumno` que lee este paso */
    public const array HISTORY_COLUMNS = ['periodo', 'no_de_control', 'materia', 'calificacion', 'tipo_evaluacion', 'estatus_materia'];

    /** @var list<string> las únicas columnas de `seleccion_materias` que lee este paso */
    public const array SELECTION_COLUMNS = ['periodo', 'no_de_control', 'materia', 'repeticion', 'status_seleccion'];

    /** El valor que gana una llave: huella (15 bits) | si es aprobada (128) y la calificación (8 bits) | línea (40 bits). */
    private const int LINE_BITS = 40;

    private const int FINGERPRINT_SHIFT = 48;

    private const int RANK_AND_LINE = (1 << self::FINGERPRINT_SHIFT) - 1;

    private const int LINE_MASK = (1 << self::LINE_BITS) - 1;

    private const int APPROVED_RANK = 128;

    /**
     * @param  (Closure(string): int)|null  $hash  la llave de 64 bits de un renglón; solo las pruebas la cambian
     */
    public function __construct(private readonly ?Closure $hash = null) {}

    public function name(): string
    {
        return 'kardex';
    }

    public function batches(SiiMigration $migration): iterable
    {
        /** @var array<string|int, string> $subjectFor */
        $subjectFor = $migration->remember('subject_for', fn () => throw new SiiStepOutOfOrder($this->name(), 'subject_for', ['materias']));
        /** @var array<string, array<string|int, string>> $subjectForPlan */
        $subjectForPlan = $migration->remember('subject_for_plan', fn () => throw new SiiStepOutOfOrder($this->name(), 'subject_for_plan', ['materias']));
        $subjects = new KardexSubjects($subjectFor, $subjectForPlan);
        $export = $migration->export();

        if (! $export->has(SiiFile::HistoriaAlumno)) {
            yield new SiiBatch('kardex', 'kardex.csv', [], incidents: [$this->warning('kardex', 'La exportación no trae historia_alumno: el kárdex no se migra.')]);

            return;
        }

        $students = $this->students();
        $tally = ['lines' => [], 'students' => 0, 'subjects' => [], 'types' => [], 'repeated' => 0];
        $best = $this->rank($export, $subjects, $students, $tally);
        [$current, $selectionIncidents] = $this->current($export, $subjects, $students, $best);

        yield new SiiBatch(
            'kardex',
            'kardex.csv',
            $this->rows($export, $subjects, $students, $best, $current),
            incidents: [...$this->historyIncidents($tally), ...$selectionIncidents],
        );
    }

    /**
     * Primera pasada por la historia: por llave, el valor de la línea que gana.
     *
     * @param  array<string|int, string>  $students  plan de Nexus por número de control
     * @param  array{lines: list<array{level: 'warning', ref: string, message: string}>, students: int, subjects: array<string|int, int>, types: array<string, int>, repeated: int}  $tally
     * @return array<int, int>
     */
    private function rank(SiiExport $export, KardexSubjects $subjects, array $students, array &$tally): array
    {
        $best = [];

        foreach ($this->history($export, $subjects, $students, $tally) as $line => $entry) {
            $value = ($entry['rank'] << self::LINE_BITS) | $line;
            $previous = $best[$entry['key']] ?? null;

            if ($previous !== null) {
                $this->sameFingerprint($previous, $entry['fingerprint']);
                $tally['repeated']++;

                if ($value < ($previous & self::RANK_AND_LINE)) {
                    continue;
                }
            }

            $best[$entry['key']] = ($entry['fingerprint'] << self::FINGERPRINT_SHIFT) | $value;
        }

        return $best;
    }

    /**
     * Los renglones de la historia que pasan al kárdex, ya en los campos de la plantilla. Con `$tally` cuenta y anota
     * lo que no pasa o no se entiende (solo en la primera pasada).
     *
     * @param  array<string|int, string>  $students  plan de Nexus por número de control
     * @param  array{lines: list<array{level: 'warning', ref: string, message: string}>, students: int, subjects: array<string|int, int>, types: array<string, int>, repeated: int}|null  $tally
     * @return Generator<int, array{key: int, fingerprint: int, rank: int, row: array<string, string|int>}>
     */
    private function history(SiiExport $export, KardexSubjects $subjects, array $students, ?array &$tally = null): Generator
    {
        $file = $export->fileName(SiiFile::HistoriaAlumno);

        foreach ($export->reader(SiiFile::HistoriaAlumno)->rows(self::HISTORY_COLUMNS) as $line => $row) {
            $result = KardexCodes::result($row['estatus_materia']);
            $unreadable = match (true) {
                $row['no_de_control'] === '' => 'Esta línea no trae número de control: no pasa al kárdex.',
                ! KardexCodes::term($row['periodo']) => 'El periodo de esta línea no es un periodo del SII (año de '.KardexCodes::FIRST_YEAR.' al próximo y 1, 2 o 3): no pasa al kárdex.',
                $result === null => 'El estatus de la materia de esta línea no es A, R ni P: no pasa al kárdex.',
                default => null,
            };

            if ($result === null || $unreadable !== null) {
                if ($tally !== null) {
                    $tally['lines'][] = $this->warning("{$file}:{$line}", (string) $unreadable);
                }

                continue;
            }

            $subject = $subjects->for($students[$row['no_de_control']] ?? null, $row['materia']);

            if (! isset($students[$row['no_de_control']]) || $subject === null) {
                if ($tally !== null && ! isset($students[$row['no_de_control']])) {
                    $tally['students']++;
                } elseif ($tally !== null) {
                    $tally['subjects'][$row['materia']] = ($tally['subjects'][$row['materia']] ?? 0) + 1;
                }

                continue;
            }

            $attempt = KardexCodes::attempt($row['tipo_evaluacion']);

            if ($tally !== null) {
                if (! KardexCodes::readableGrade($row['calificacion'])) {
                    $tally['lines'][] = $this->warning("{$file}:{$line}", 'La calificación de esta línea no se entiende: pasa al kárdex sin calificación.');
                }

                if ($attempt === null) {
                    $type = strtoupper(trim($row['tipo_evaluacion']));
                    $tally['types'][$type] = ($tally['types'][$type] ?? 0) + 1;
                }
            }

            $grade = KardexCodes::grade($row['calificacion'], $result);
            $tuple = "{$row['no_de_control']}|{$subject}|{$row['periodo']}";

            yield $line => [
                'key' => $this->key($tuple),
                'fingerprint' => self::fingerprint($tuple),
                'rank' => ($result === KardexResult::Aprobada ? self::APPROVED_RANK : 0) | ($grade ?? 0),
                'row' => [
                    'control_number' => $row['no_de_control'],
                    'subject_code' => $subject,
                    'term_code' => $row['periodo'],
                    'grade' => $grade ?? '',
                    'result' => $result->value,
                    'attempt' => $attempt ?? 1,
                    'sii_subject_code' => $row['materia'],
                ],
            ];
        }
    }

    /**
     * Las materias de los periodos en curso, como «cursando», y los avisos de la selección. Una pasada cuenta, por
     * periodo, cuántos inscritos ya están en la historia; la segunda junta los de los periodos en curso.
     *
     * @param  array<string|int, string>  $students  plan de Nexus por número de control
     * @param  array<int, int>  $best
     * @return array{0: list<array<string, string|int>>, 1: list<array{level: 'warning', ref: string, message: string}>}
     */
    private function current(SiiExport $export, KardexSubjects $subjects, array $students, array $best): array
    {
        if (! $export->has(SiiFile::SeleccionMaterias)) {
            return [[], [$this->warning('kardex', 'La exportación no trae seleccion_materias: ninguna materia del periodo en curso pasa como cursando.')]];
        }

        /** @var array<string|int, array{inHistory: int, enrolled: int, missing: int, notEnrolled: int, students: int, subjects: array<string|int, int>, repeated: int}> $terms */
        $terms = [];

        foreach ($this->selection($export, $subjects, $students, $best) as $entry) {
            $term = $entry['term'];
            $terms[$term] ??= ['inHistory' => 0, 'enrolled' => 0, 'missing' => 0, 'notEnrolled' => 0, 'students' => 0, 'subjects' => [], 'repeated' => 0];

            if (! $entry['student']) {
                $terms[$term]['students']++;
            } elseif ($entry['subject'] === null) {
                $terms[$term]['subjects'][$entry['materia']] = ($terms[$term]['subjects'][$entry['materia']] ?? 0) + 1;
            } elseif ($entry['inHistory']) {
                $terms[$term]['inHistory'] += $entry['enrolled'] ? 1 : 0;
            } else {
                $terms[$term]['missing']++;
                $terms[$term]['enrolled'] += $entry['enrolled'] ? 1 : 0;
                $terms[$term]['notEnrolled'] += $entry['enrolled'] ? 0 : 1;
            }
        }

        $open = array_filter($terms, fn (array $stats): bool => $stats['inHistory'] * 2 < $stats['inHistory'] + $stats['enrolled']);
        /** @var array<int, array<string, string|int>> $candidates */
        $candidates = [];

        foreach ($open === [] ? [] : $this->selection($export, $subjects, $students, $best) as $entry) {
            if (! isset($open[$entry['term']]) || ! $entry['student'] || $entry['subject'] === null || $entry['inHistory'] || ! $entry['enrolled']) {
                continue;
            }

            if (isset($candidates[$entry['key']])) {
                $terms[$entry['term']]['repeated']++;
                $candidates[$entry['key']]['attempt'] = max($candidates[$entry['key']]['attempt'], $entry['attempt']);

                continue;
            }

            $candidates[$entry['key']] = [
                'control_number' => $entry['controlNumber'],
                'subject_code' => $entry['subject'],
                'term_code' => (string) $entry['term'],
                'grade' => '',
                'result' => KardexResult::Cursando->value,
                'attempt' => $entry['attempt'],
                'sii_subject_code' => $entry['materia'],
            ];
        }

        return [array_values($candidates), $this->selectionIncidents($terms, array_keys($open))];
    }

    /**
     * Los renglones de la selección con su llave y si ya están en la historia.
     *
     * @param  array<string|int, string>  $students  plan de Nexus por número de control
     * @param  array<int, int>  $best
     * @return Generator<int, array{term: string, controlNumber: string, materia: string, student: bool, subject: string|null, key: int, inHistory: bool, enrolled: bool, attempt: int}>
     */
    private function selection(SiiExport $export, KardexSubjects $subjects, array $students, array $best): Generator
    {
        foreach ($export->reader(SiiFile::SeleccionMaterias)->rows(self::SELECTION_COLUMNS) as $row) {
            if (! KardexCodes::term($row['periodo']) || $row['no_de_control'] === '') {
                continue;
            }

            $subject = $subjects->for($students[$row['no_de_control']] ?? null, $row['materia']);
            $tuple = "{$row['no_de_control']}|{$subject}|{$row['periodo']}";
            $key = $this->key($tuple);
            $known = $best[$key] ?? null;

            if ($known !== null && $subject !== null) {
                $this->sameFingerprint($known, self::fingerprint($tuple));
            }

            yield [
                'term' => $row['periodo'],
                'controlNumber' => $row['no_de_control'],
                'materia' => $row['materia'],
                'student' => isset($students[$row['no_de_control']]),
                'subject' => $subject,
                'key' => $key,
                'inHistory' => $known !== null && $subject !== null,
                'enrolled' => strtoupper(trim($row['status_seleccion'])) === 'I',
                'attempt' => strtoupper(trim($row['repeticion'])) === 'S' ? 2 : 1,
            ];
        }
    }

    /**
     * @param  array<string|int, array{inHistory: int, enrolled: int, missing: int, notEnrolled: int, students: int, subjects: array<string|int, int>, repeated: int}>  $terms
     * @param  list<string|int>  $open
     * @return list<array{level: 'warning', ref: string, message: string}>
     */
    private function selectionIncidents(array $terms, array $open): array
    {
        $incidents = [];
        $closedMissing = array_sum(array_column(array_diff_key($terms, array_flip($open)), 'missing'));

        if ($closedMissing > 0) {
            $incidents[] = $this->warning('kardex', "{$closedMissing} renglones de seleccion_materias de periodos cerrados no están en la historia: no pasan al kárdex, que en esos periodos sale solo de la historia.");
        }

        if ($open === [] && $terms !== []) {
            $incidents[] = $this->warning('kardex', 'Todos los periodos de seleccion_materias ya están en la historia: ninguna materia pasa como cursando.');
        }

        sort($open);

        foreach ($open as $term) {
            $stats = $terms[$term];
            $subjects = $stats['subjects'];
            ksort($subjects, SORT_STRING);

            foreach ($subjects as $materia => $count) {
                $incidents[] = $this->warning((string) $materia, "La materia {$materia} del SII no tiene equivalente en Nexus: {$count} renglones de la selección de {$term} no pasan al kárdex.");
            }

            foreach ([
                [$stats['students'], 'son de alumnos que no están en Nexus: no pasan al kárdex.'],
                [$stats['notEnrolled'], 'no están inscritos (status_seleccion distinto de I): no pasan al kárdex.'],
                [$stats['inHistory'], 'ya están en la historia: queda el de la historia.'],
                [$stats['repeated'], 'repiten alumno, materia de Nexus y periodo: pasa uno, con la oportunidad mayor.'],
            ] as [$count, $text]) {
                if ($count > 0) {
                    $incidents[] = $this->warning('kardex', "{$count} renglones de la selección de {$term} {$text}");
                }
            }
        }

        return $incidents;
    }

    /**
     * Segunda pasada: las líneas de la historia que ganaron su llave y, al final, las materias en curso.
     *
     * @param  array<string|int, string>  $students  plan de Nexus por número de control
     * @param  array<int, int>  $best
     * @param  list<array<string, string|int>>  $current
     * @return Generator<int, array<string, string|int>>
     */
    private function rows(SiiExport $export, KardexSubjects $subjects, array $students, array $best, array $current): Generator
    {
        foreach ($this->history($export, $subjects, $students) as $line => $entry) {
            if (($best[$entry['key']] & self::LINE_MASK) === $line) {
                yield $entry['row'];
            }
        }

        foreach ($current as $row) {
            yield $row;
        }
    }

    /**
     * @param  array{lines: list<array{level: 'warning', ref: string, message: string}>, students: int, subjects: array<string|int, int>, types: array<string, int>, repeated: int}  $tally
     * @return list<array{level: 'warning', ref: string, message: string}>
     */
    private function historyIncidents(array $tally): array
    {
        $incidents = $tally['lines'];

        if ($tally['students'] > 0) {
            $incidents[] = $this->warning('kardex', "{$tally['students']} renglones de la historia son de alumnos que no están en Nexus: no pasan al kárdex.");
        }

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
     * Los alumnos que ya están en Nexus: número de control → clave de su plan.
     *
     * @return array<string|int, string>
     */
    private function students(): array
    {
        $plans = StudyPlan::withTrashed()->pluck('code', 'id')->all();

        return array_map(
            fn (?string $plan): string => $plan === null ? '' : (string) ($plans[$plan] ?? ''),
            Student::query()->pluck('study_plan_id', 'control_number')->all(),
        );
    }

    /**
     * La llave de 64 bits de un renglón (alumno, materia de Nexus y periodo).
     */
    private function key(string $tuple): int
    {
        if ($this->hash !== null) {
            return ($this->hash)($tuple);
        }

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('q', hash('xxh3', $tuple, true));

        return $unpacked[1];
    }

    /**
     * Una huella de 15 bits del renglón, independiente de la llave, para notar si dos llaves distintas chocan.
     */
    private static function fingerprint(string $tuple): int
    {
        return crc32($tuple) & 0x7FFF;
    }

    /**
     * @throws RuntimeException si la llave ya era de otro renglón: el paso se detiene antes de perderlo
     */
    private function sameFingerprint(int $stored, int $fingerprint): void
    {
        if (($stored >> self::FINGERPRINT_SHIFT) !== $fingerprint) {
            throw new RuntimeException('Dos renglones distintos del kárdex chocaron en la misma llave de 64 bits; el paso se detiene para no perder ninguno.');
        }
    }

    /**
     * @return array{level: 'warning', ref: string, message: string}
     */
    private function warning(string $ref, string $message): array
    {
        return ['level' => 'warning', 'ref' => $ref, 'message' => $message];
    }
}
