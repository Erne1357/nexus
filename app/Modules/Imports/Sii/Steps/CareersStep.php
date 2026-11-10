<?php

namespace App\Modules\Imports\Sii\Steps;

use App\Modules\Imports\Sii\KardexCodes;
use App\Modules\Imports\Sii\SiiBatch;
use App\Modules\Imports\Sii\SiiCuratedMismatch;
use App\Modules\Imports\Sii\SiiFile;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\SiiStep;

/**
 * Carreras y planes de estudio (D15, reglas-de-negocio.md "Reglas de datos"). Las carreras y retículas del SII no
 * se importan tal cual: `Curated/planes.csv` dice a qué plan oficial y carrera de Nexus se funde cada
 * (carrera, retícula) del SII (o `incluir=no`), y `Curated/carreras.csv` da el nombre y el departamento de cada
 * carrera.
 *
 * Contrato: cada (carrera, retícula) del SII tiene exactamente una fila curada y cada fila curada existe en el SII;
 * cada carrera incluida tiene fila en `carreras.csv`. Si no, el paso se detiene antes de escribir
 * (SiiCuratedMismatch).
 *
 * Todo lo derivado sale de los alumnos, solo agregado (conteos por carrera, retícula, campus y periodo; nunca
 * valores personales): la oferta por campus, el plan vigente y el plan de nuevo ingreso (el que más nuevo
 * ingreso recibió en el último periodo de admisión regular, supuesto por confirmar).
 *
 * Los créditos totales de cada plan son los `creditos_totales` de su retícula del SII, contra los que se mide el avance
 * del alumno, como en el SII (decidido el 10 de noviembre de 2026); vacíos o en cero, el plan queda sin ellos. Si las
 * retículas de un plan traen créditos distintos, quedan los de la presencial y, si no la hay o también difieren, los
 * mayores, con aviso (supuesto por confirmar).
 *
 * Retícula histórica (D18, confirmado el 10 de noviembre de 2026): un plan de Nexus que no es vigente ni de nuevo
 * ingreso (`current` e `intake` de este lote) y en el que ningún alumno está inscrito en el periodo actual. El periodo
 * actual sale de `seleccion_materias`, sin los veranos (terminan en 2): el periodo regular válido más reciente
 * (KardexCodes::term(), termina en 1 o 3); si tiene menos de la mitad de alumnos distintos que el regular anterior,
 * todavía se está abriendo y cuentan los inscritos en los dos (el umbral de la mitad, supuesto por confirmar). Un
 * alumno está inscrito si tiene un renglón de la selección en ese periodo; su retícula es la de `alumnos_todos`, y un
 * plan que junta varias retículas del SII es vivo si cualquiera tiene un inscrito. La selección se lee una sola vez y
 * solo guarda los números de control de los dos periodos regulares más recientes vistos hasta ahí. Sin
 * `seleccion_materias` (es opcional) o sin un periodo regular válido, ningún plan es histórico y se avisa una vez; si
 * no, un aviso dice qué periodo o periodos tomó y con cuántos alumnos.
 *
 * Recuerda `plan_for`: `"{carrera}/{retícula}"` del SII → plan, carrera y modalidad de Nexus, solo de lo incluido;
 * `plan_version`: clave del plan de Nexus → su versión (texto vacío si no se sabe); y `historical_plans`: clave del
 * plan de Nexus → true, de las retículas históricas. Entrega dos lotes, en este orden: `careers` y `study_plans`.
 */
final class CareersStep implements SiiStep
{
    private const int KEY_CAP = 20;

    /** @var list<string> */
    private const array ACTIVE_STATUSES = ['ACT', 'INS', 'BT1', 'BT2', 'BT3', 'BT4'];

    /** @var list<string> las únicas columnas de `alumnos` que lee este paso (nunca el NIP ni la CURP) */
    public const array STUDENT_COLUMNS = ['no_de_control', 'carrera', 'reticula', 'periodo_ingreso_it', 'campus', 'estatus_alumno'];

    /** @var list<string> las únicas columnas de `seleccion_materias` que lee este paso */
    public const array SELECTION_COLUMNS = ['periodo', 'no_de_control'];

    private const array CAMPUSES = ['1' => 'C1', '2' => 'C2'];

    public function name(): string
    {
        return 'carreras';
    }

    public function batches(SiiMigration $migration): iterable
    {
        $plans = $this->plansByKey($migration);
        $careers = $this->careersByCode($migration);
        [$siiRows, $credits] = $this->siiCareerRows($migration);
        $this->checkContract($plans, $careers, array_keys($siiRows));

        $planFor = $migration->remember('plan_for', fn (): array => $this->planFor($plans));
        $versions = $migration->remember('plan_version', fn (): array => $this->versions($planFor, $siiRows));
        $enrolled = $this->enrolledNow($migration);
        $facts = $this->studentFacts($migration, $planFor, $enrolled['students'] ?? []);
        [$plansBatch, $historical] = $this->plansBatch($planFor, $versions, $facts, $credits, $enrolled['terms'] ?? null);
        $migration->remember('historical_plans', fn (): array => $historical);

        yield $this->careersBatch($plans, $careers, $planFor, $facts);
        yield $plansBatch;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function plansByKey(SiiMigration $migration): array
    {
        $byKey = [];

        foreach ($migration->curated('planes') as $row) {
            $byKey[trim($row['sii_carrera']).'/'.trim($row['sii_reticula'])] = $row;
        }

        return $byKey;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function careersByCode(SiiMigration $migration): array
    {
        $byCode = [];

        foreach ($migration->curated('carreras') as $row) {
            $byCode[trim($row['carrera'])] = $row;
        }

        return $byCode;
    }

    /**
     * Las fechas de inicio de cada (carrera, retícula) del SII y, de las que los traen como un entero mayor que cero,
     * todos sus créditos totales distintos, de menor a mayor (una retícula puede venir en varios renglones).
     *
     * @return array{array<string, list<string>>, array<string, list<int>>}
     */
    private function siiCareerRows(SiiMigration $migration): array
    {
        $rows = [];
        $credits = [];

        foreach ($migration->export()->reader(SiiFile::Carreras)->rows() as $row) {
            $key = trim($row['carrera']).'/'.trim($row['reticula']);
            $rows[$key][] = $row['fecha_inicio'] ?? '';
            $value = trim($row['creditos_totales'] ?? '');

            if (is_numeric($value) && (int) $value > 0) {
                $credits[$key][(int) $value] = true;
            }
        }

        $credits = array_map(function (array $values): array {
            $values = array_keys($values);
            sort($values);

            return $values;
        }, $credits);

        return [$rows, $credits];
    }

    /**
     * @param  array<string, array<string, string>>  $plans
     * @param  array<string, array<string, string>>  $careers
     * @param  list<string>  $siiKeys
     *
     * @throws SiiCuratedMismatch
     */
    private function checkContract(array $plans, array $careers, array $siiKeys): void
    {
        $siiKeys = array_map('strval', $siiKeys);
        $planKeys = array_map('strval', array_keys($plans));

        $missing = array_values(array_diff($siiKeys, $planKeys));
        $extra = array_values(array_diff($planKeys, $siiKeys));

        if ($missing !== [] || $extra !== []) {
            throw new SiiCuratedMismatch('planes', $this->capped($missing), $this->capped($extra));
        }

        $included = [];
        foreach ($plans as $row) {
            if ($this->included($row)) {
                $included[(string) $row['carrera']] = true;
            }
        }

        $withoutCareer = array_values(array_diff(array_map('strval', array_keys($included)), array_map('strval', array_keys($careers))));

        if ($withoutCareer !== []) {
            throw new SiiCuratedMismatch('carreras', $this->capped($withoutCareer), []);
        }
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function capped(array $keys): array
    {
        sort($keys);

        if (count($keys) <= self::KEY_CAP) {
            return $keys;
        }

        return [...array_slice($keys, 0, self::KEY_CAP), '… y '.(count($keys) - self::KEY_CAP).' más'];
    }

    /**
     * @param  array<string, string>  $row
     */
    private function included(array $row): bool
    {
        return strtolower(trim($row['incluir'])) === 'si';
    }

    /**
     * @param  array<string, array<string, string>>  $plans
     * @return array<string, array{plan: string, career: string, modality: string}>
     */
    private function planFor(array $plans): array
    {
        $map = [];

        foreach ($plans as $key => $row) {
            if ($this->included($row)) {
                $map[(string) $key] = ['plan' => trim($row['plan']), 'career' => trim($row['carrera']), 'modality' => trim($row['modalidad'])];
            }
        }

        return $map;
    }

    /**
     * La versión de cada plan de Nexus, por su clave (version()).
     *
     * @param  array<string, array{plan: string, career: string, modality: string}>  $planFor
     * @param  array<string, list<string>>  $siiRows
     * @return array<string, string>
     */
    private function versions(array $planFor, array $siiRows): array
    {
        $keys = [];
        foreach ($planFor as $key => $info) {
            $keys[$info['plan']][] = (string) $key;
        }

        $versions = [];
        foreach ($keys as $code => $planKeys) {
            $versions[(string) $code] = $this->version((string) $code, $planKeys, $siiRows);
        }

        return $versions;
    }

    /**
     * Los números de control inscritos en el periodo actual, de `seleccion_materias`: una sola pasada que solo guarda los
     * de los dos periodos regulares más recientes vistos hasta ahí. El más reciente, y también el anterior si el más
     * reciente tiene menos de la mitad de alumnos distintos (todavía se está abriendo). Null si la exportación no trae el
     * archivo o ningún renglón trae un periodo regular válido.
     *
     * @return array{terms: list<array{term: string, students: int}>, students: array<string|int, true>}|null
     */
    private function enrolledNow(SiiMigration $migration): ?array
    {
        $export = $migration->export();

        if (! $export->has(SiiFile::SeleccionMaterias)) {
            return null;
        }

        /** @var array<int, array<string|int, true>> $byTerm */
        $byTerm = [];

        foreach ($export->reader(SiiFile::SeleccionMaterias)->rows(self::SELECTION_COLUMNS) as $row) {
            $period = $row['periodo'];

            if ($row['no_de_control'] === '' || ! KardexCodes::term($period) || ! in_array(substr($period, -1), ['1', '3'], true)) {
                continue;
            }

            $term = (int) $period;

            if (! isset($byTerm[$term])) {
                if (count($byTerm) === 2 && $term < min(array_keys($byTerm))) {
                    continue;
                }

                $byTerm[$term] = [];

                if (count($byTerm) > 2) {
                    unset($byTerm[min(array_keys($byTerm))]);
                }
            }

            $byTerm[$term][$row['no_de_control']] = true;
        }

        if ($byTerm === []) {
            return null;
        }

        krsort($byTerm);
        $terms = array_keys($byTerm);
        $opening = isset($terms[1]) && count($byTerm[$terms[0]]) * 2 < count($byTerm[$terms[1]]);
        $used = $opening ? $terms : [$terms[0]];

        return [
            'terms' => array_map(fn (int $term): array => ['term' => (string) $term, 'students' => count($byTerm[$term])], $used),
            'students' => $opening ? $byTerm[$terms[0]] + $byTerm[$terms[1]] : $byTerm[$terms[0]],
        ];
    }

    /**
     * Recorre una vez a los alumnos y agrega: campus por carrera de Nexus, retícula con alumnos activos, retícula con
     * alumnos inscritos en el periodo actual y nuevo ingreso por periodo regular y retícula. Un campus que no es `1`
     * ni `2` queda contado, sin valores personales.
     *
     * @param  array<string, array{plan: string, career: string, modality: string}>  $planFor
     * @param  array<string|int, true>  $enrolled  números de control inscritos en el periodo actual
     * @return array{campuses: array<string, array<string, true>>, unknownCampuses: array<string, int>, active: array<string, true>, enrolled: array<string, true>, entrants: array<string, array<string, int>>}
     */
    private function studentFacts(SiiMigration $migration, array $planFor, array $enrolled): array
    {
        $facts = ['campuses' => [], 'unknownCampuses' => [], 'active' => [], 'enrolled' => [], 'entrants' => []];

        foreach ($migration->export()->reader(SiiFile::Alumnos)->rows(self::STUDENT_COLUMNS) as $row) {
            $key = trim($row['carrera']).'/'.trim($row['reticula']);
            $period = trim($row['periodo_ingreso_it'] ?? '');

            if ($period !== '' && in_array(substr($period, -1), ['1', '3'], true)) {
                $facts['entrants'][$period][$key] = ($facts['entrants'][$period][$key] ?? 0) + 1;
            }

            if (! isset($planFor[$key])) {
                continue;
            }

            $campus = trim($row['campus'] ?? '');

            if (isset(self::CAMPUSES[$campus])) {
                $facts['campuses'][$planFor[$key]['career']][self::CAMPUSES[$campus]] = true;
            } else {
                $label = $planFor[$key]['career'].' (campus '.($campus === '' ? 'vacío' : $campus).')';
                $facts['unknownCampuses'][$label] = ($facts['unknownCampuses'][$label] ?? 0) + 1;
            }

            if (in_array(strtoupper(trim($row['estatus_alumno'] ?? '')), self::ACTIVE_STATUSES, true)) {
                $facts['active'][$key] = true;
            }

            if (isset($enrolled[$row['no_de_control']])) {
                $facts['enrolled'][$key] = true;
            }
        }

        return $facts;
    }

    /**
     * @param  array<string, array<string, string>>  $plans
     * @param  array<string, array<string, string>>  $careers
     * @param  array<string, array{plan: string, career: string, modality: string}>  $planFor
     * @param  array{campuses: array<string, array<string, true>>, unknownCampuses: array<string, int>, active: array<string, true>, enrolled: array<string, true>, entrants: array<string, array<string, int>>}  $facts
     */
    private function careersBatch(array $plans, array $careers, array $planFor, array $facts): SiiBatch
    {
        $incidents = [];

        foreach ($plans as $key => $row) {
            if (! $this->included($row)) {
                $incidents[] = ['level' => 'warning', 'ref' => (string) $key, 'message' => "Retícula {$key} excluida del curado: ".trim($row['nota'])];
            }
        }

        foreach ($facts['unknownCampuses'] as $label => $count) {
            $incidents[] = ['level' => 'warning', 'ref' => $label, 'message' => "{$count} alumno(s) con campus que no es 1 ni 2: no cuentan para la oferta de la carrera."];
        }

        $rows = [];
        $codes = array_unique(array_column($planFor, 'career'));
        sort($codes);

        foreach ($codes as $code) {
            $campuses = array_keys($facts['campuses'][$code] ?? []);
            sort($campuses);

            if ($campuses === []) {
                $campuses = ['C1'];
                $incidents[] = ['level' => 'warning', 'ref' => $code, 'message' => "Carrera {$code}: carrera sin alumnos: se ofrece en C1 (supuesto por confirmar)"];
            }

            foreach ($campuses as $campus) {
                $rows[] = [
                    'code' => $code,
                    'name' => trim($careers[$code]['nombre']),
                    'department' => trim($careers[$code]['departamento']),
                    'campus' => $campus,
                ];
            }
        }

        return new SiiBatch('careers', 'carreras.csv', $rows, incidents: $incidents);
    }

    /**
     * @param  array<string, array{plan: string, career: string, modality: string}>  $planFor
     * @param  array<string, string>  $versions
     * @param  array{campuses: array<string, array<string, true>>, unknownCampuses: array<string, int>, active: array<string, true>, enrolled: array<string, true>, entrants: array<string, array<string, int>>}  $facts
     * @param  array<string, list<int>>  $credits  créditos totales distintos por (carrera, retícula) del SII
     * @param  list<array{term: string, students: int}>|null  $terms  los periodos actuales de la selección y sus alumnos; null si no se sabe
     * @return array{SiiBatch, array<string, true>} el lote y las retículas históricas
     */
    private function plansBatch(array $planFor, array $versions, array $facts, array $credits, ?array $terms): array
    {
        $period = $facts['entrants'] === [] ? null : (string) max(array_map('strval', array_keys($facts['entrants'])));
        $entrants = $period === null ? [] : $facts['entrants'][$period];

        $plans = [];
        foreach ($planFor as $key => $info) {
            $plans[$info['plan']]['career'] = $info['career'];
            $plans[$info['plan']]['keys'][] = (string) $key;
        }
        ksort($plans);

        $byCareer = [];
        foreach ($plans as $code => $plan) {
            $count = array_sum(array_map(fn (string $key): int => $entrants[$key] ?? 0, $plan['keys']));
            $byCareer[$plan['career']][(string) $code] = $count;
        }

        $incidents = [];
        $intake = [];
        ksort($byCareer);

        foreach ($byCareer as $career => $counts) {
            $top = max($counts);
            $winners = array_keys(array_filter($counts, fn (int $count): bool => $count === $top));

            if ($top === 0 || $period === null) {
                $incidents[] = ['level' => 'warning', 'ref' => (string) $career, 'message' => "Carrera {$career}: sin nuevo ingreso en el último periodo regular ({$period}); no hay plan de nuevo ingreso."];
            } elseif (count($winners) > 1) {
                $incidents[] = ['level' => 'warning', 'ref' => (string) $career, 'message' => "Carrera {$career}: empate de nuevo ingreso en el periodo {$period} entre ".implode(', ', $winners).'; no hay plan de nuevo ingreso.'];
            } else {
                $intake[$winners[0]] = true;
            }
        }

        $rows = [];
        $historical = [];
        foreach ($plans as $code => $plan) {
            $current = isset($intake[$code]) || array_filter($plan['keys'], fn (string $key): bool => isset($facts['active'][$key])) !== [];

            if ($terms !== null && ! $current && array_filter($plan['keys'], fn (string $key): bool => isset($facts['enrolled'][$key])) === []) {
                $historical[(string) $code] = true;
            }

            $rows[] = [
                'code' => (string) $code,
                'career' => $plan['career'],
                'name' => '',
                'version' => $versions[(string) $code],
                'current' => $current ? 'Sí' : 'No',
                'intake' => isset($intake[$code]) ? 'Sí' : 'No',
                'credits' => $this->planCredits((string) $code, $plan['keys'], $planFor, $credits, $incidents),
                'historical' => isset($historical[$code]) ? 'Sí' : 'No',
            ];
        }

        $incidents[] = ['level' => 'warning', 'ref' => 'seleccion_materias', 'message' => $this->periodMessage($terms, count($historical), count($plans))];

        return [new SiiBatch('study_plans', 'planes.csv', $rows, incidents: $incidents), $historical];
    }

    /**
     * El aviso de qué periodo o periodos de la selección se tomaron como actuales, con cuántos alumnos, y cuántos planes
     * quedaron como retículas históricas.
     *
     * @param  list<array{term: string, students: int}>|null  $terms
     */
    private function periodMessage(?array $terms, int $historical, int $plans): string
    {
        if ($terms === null) {
            return 'La exportación no trae seleccion_materias con periodos regulares válidos: no se sabe quién está inscrito en el periodo actual, así que ningún plan se trata como retícula histórica (D18).';
        }

        $enrolled = fn (int $count): string => $count === 1 ? '1 alumno inscrito' : "{$count} alumnos inscritos";
        $outcome = "{$historical} de {$plans} planes no tienen alumnos inscritos";
        $closing = 'ni son vigentes, y quedan como retículas históricas (confirmado, 10 de noviembre de 2026).';

        if (count($terms) === 1) {
            return "El periodo actual es el {$terms[0]['term']} ({$enrolled($terms[0]['students'])} en seleccion_materias): {$outcome} en él {$closing}";
        }

        $previous = $terms[1]['students'] === 1 ? '1 alumno' : "{$terms[1]['students']} alumnos";

        return "El periodo {$terms[0]['term']} ({$enrolled($terms[0]['students'])} en seleccion_materias) todavía se está abriendo, con menos de la mitad que el {$terms[1]['term']} ({$previous}): cuentan los inscritos en los dos (umbral de la mitad, supuesto por confirmar). {$outcome} {$closing}";
    }

    /**
     * Los créditos totales de un plan desde los de sus retículas del SII: si difieren, los de la presencial y, si no
     * la hay o también difieren, los mayores, con aviso.
     *
     * @param  list<string>  $keys
     * @param  array<string, array{plan: string, career: string, modality: string}>  $planFor
     * @param  array<string, list<int>>  $credits
     * @param  list<array{level: string, ref: string, message: string}>  $incidents
     */
    private function planCredits(string $code, array $keys, array $planFor, array $credits, array &$incidents): ?string
    {
        sort($keys, SORT_STRING);
        $known = [];

        foreach ($keys as $key) {
            if (isset($credits[$key])) {
                $known[$key] = max($credits[$key]);

                if (count($credits[$key]) > 1) {
                    $incidents[] = ['level' => 'warning', 'ref' => $key, 'message' => "La retícula {$key} del SII viene varias veces en carreras con créditos totales distintos (".implode(', ', $credits[$key])."): quedan {$known[$key]}, los mayores (supuesto por confirmar)."];
                }
            }
        }

        if ($known === []) {
            return null;
        }

        if (count(array_unique($known)) === 1) {
            return (string) reset($known);
        }

        $presencial = array_unique(array_intersect_key($known, array_filter($planFor, fn (array $info): bool => $info['modality'] === 'presencial')));
        [$kept, $from] = count($presencial) === 1 ? [reset($presencial), 'de la presencial'] : [max($known), 'los mayores'];
        $listed = implode(', ', array_map(fn (string $key): string => "{$key} {$known[$key]}", array_map('strval', array_keys($known))));
        $incidents[] = ['level' => 'warning', 'ref' => $code, 'message' => "El plan {$code} junta retículas del SII con créditos totales distintos ({$listed}): quedan {$kept}, {$from} (supuesto por confirmar)."];

        return (string) $kept;
    }

    /**
     * El año de la clave del plan (cuatro dígitos, o dos como `19xx`); si no trae, el de la fecha de inicio más
     * antigua de sus retículas del SII.
     *
     * @param  list<string>  $keys
     * @param  array<string, list<string>>  $siiRows
     */
    private function version(string $code, array $keys, array $siiRows): string
    {
        if (preg_match('/(?<!\d)((?:19|20)\d{2})(?!\d)/', $code, $match) === 1) {
            return $match[1];
        }

        if (preg_match('/-(\d{2})-/', $code, $match) === 1) {
            return '19'.$match[1];
        }

        $years = [];
        foreach ($keys as $key) {
            foreach ($siiRows[$key] ?? [] as $date) {
                if (preg_match('/(?<!\d)((?:19|20)\d{2})(?!\d)/', $date, $match) === 1) {
                    $years[] = $match[1];
                }
            }
        }

        return $years === [] ? '' : min($years);
    }
}
