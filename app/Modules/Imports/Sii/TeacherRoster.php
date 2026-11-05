<?php

namespace App\Modules\Imports\Sii;

use App\Modules\Faculty\Rules\TeacherRfc;
use Illuminate\Support\Facades\Validator;

/**
 * Los docentes de la migración del SII, armados una sola vez por corrida (`TeachersStep` los entrega y
 * `TeacherHistoryStep` los reutiliza). Reglas de reglas-de-negocio.md "Reglas de datos": D4 (unión de `docentes` y
 * `docentes2` por RFC), D10 (solo nombramiento D o con algún grupo), D9 (activo si tiene grupo en el periodo vigente
 * o está en `docentes2` con nombramiento D); lo demás son supuestos por confirmar: el estatus 04 (cambio de RFC) se
 * funde con el RFC nuevo, el departamento cae al de sus grupos recientes, el grado queda vacío.
 *
 * De `grupos` (79 mil renglones) solo guarda conteos por RFC, periodo y materia. Nada de lo que reporta nombra
 * personas salvo por RFC, y solo en `_incidencias.csv`.
 */
final class TeacherRoster
{
    /** @var list<string> las únicas columnas de `docentes` que se leen (nunca CURP, domicilio, teléfono ni familiares) */
    public const array STAFF_COLUMNS = ['rfc', 'clave_area', 'nombramiento', 'status_empleado', 'nombre_empleado', 'apellidos_empleado', 'correo_electronico', 'inicio_plantel'];

    /** @var list<string> las únicas columnas de `docentes2` que se leen */
    public const array STAFF2_COLUMNS = ['rfc', 'apellidop', 'apellidom', 'nombre_empleado', 'id_unidad_organica', 'fecha_ingreso_institucion'];

    private const int REGULAR_PERIODS = 4;

    private const string DEGREE_WARNING = 'el grado viene en letras sin catálogo (nivel_estudios / grado_academico): pendiente';

    /** @var list<array<string, string>> */
    public array $rows = [];

    /** @var array<string, string> RFC del SII (también los viejos de estatus 04) al RFC del docente en Nexus */
    public array $teacherFor = [];

    /** @var list<array{level: 'error'|'warning', ref: string, message: string}> */
    public array $incidents = [];

    /** @var array<string, array<string, array<string, int>>> grupos por RFC del docente en Nexus, periodo y materia */
    public array $cells = [];

    /** @var array<string, array<string, array<string, true>>> claves `MATERIA|GRUPO` por RFC del docente en Nexus y periodo */
    public array $groupKeys = [];

    /** @var int grupos sin RFC */
    public int $withoutRfc = 0;

    /** @var int grupos de un RFC que no es de ningún docente migrado */
    public int $unmigratedGroups = 0;

    public static function normalizeRfc(string $rfc): string
    {
        return TeacherRfc::normalize($rfc) ?? trim($rfc);
    }

    public static function groupKey(string $materia, string $grupo): string
    {
        return mb_strtoupper(trim($materia)).'|'.mb_strtoupper(trim($grupo));
    }

    /**
     * La clave del grupo padre de `paralelo_de` (`<materia con relleno> <grupo>`): la primera palabra es la materia y
     * la última el grupo. Null si viene vacío o no se entiende.
     */
    public static function parentKey(string $paraleloDe): ?string
    {
        $tokens = preg_split('/\s+/u', trim($paraleloDe), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return count($tokens) < 2 ? null : self::groupKey($tokens[0], $tokens[array_key_last($tokens)]);
    }

    /**
     * @param  array<string|int, string|null>  $departmentForArea
     */
    public static function build(SiiMigration $migration, array $departmentForArea): self
    {
        $roster = new self;
        $roster->fill($migration, $departmentForArea);

        return $roster;
    }

    /**
     * @param  array<string|int, string|null>  $departmentForArea
     */
    private function fill(SiiMigration $migration, array $departmentForArea): void
    {
        $export = $migration->export();
        $staff = [];
        $staff2 = [];

        foreach ($export->reader(SiiFile::Docentes)->rows(self::STAFF_COLUMNS) as $row) {
            $rfc = self::normalizeRfc($row['rfc']);
            if ($rfc !== '') {
                $staff[$rfc] ??= $row;
            }
        }

        foreach ($export->reader(SiiFile::Docentes2)->rows(self::STAFF2_COLUMNS) as $row) {
            $rfc = self::normalizeRfc($row['rfc']);
            if ($rfc !== '') {
                $staff2[$rfc] ??= $row;
            }
        }

        [$raw, $periods, $rawKeys] = $this->readGroups($export);
        $currentPeriod = $periods === [] ? '' : max($periods);

        $included = [];
        foreach ($staff as $rfc => $row) {
            if (strtoupper($row['nombramiento']) === 'D') {
                $included[$rfc] = true;
            }
        }
        $unknown = 0;
        foreach ($raw as $rfc => $periodCells) {
            if (isset($staff[$rfc]) || isset($staff2[$rfc])) {
                $included[$rfc] = true;
            } else {
                $unknown += array_sum(array_map(fn (array $c): int => array_sum($c), $periodCells));
            }
        }
        ksort($included);

        $merge = $this->mergeChangedRfcs(array_keys($included), $staff);
        $materiaArea = $this->materiaAreas($export);

        $emails = 0;
        $noDepartment = 0;
        $nameless = 0;

        foreach (array_keys($included) as $rfc) {
            if (isset($merge[$rfc])) {
                continue;
            }

            $sources = [$rfc, ...array_keys(array_filter($merge, fn (string $new): bool => $new === $rfc))];
            $cells = $this->mergeCells($raw, $sources);
            $d = $staff[$rfc] ?? null;
            $d2 = $staff2[$rfc] ?? null;

            $name = $this->name($d, $d2);
            if ($name === '') {
                $nameless++;
                $this->incidents[] = $this->warning($rfc, "El docente {$rfc} no trae nombre: no se migra.");

                continue;
            }

            $department = $this->department($d, $d2, $cells, $materiaArea, $departmentForArea);
            if ($department === null) {
                $noDepartment++;
                $this->incidents[] = $this->warning($rfc, "El docente {$rfc} no tiene departamento académico (ni por su adscripción ni por sus grupos recientes): no se migra.");

                continue;
            }

            $email = strtolower($d['correo_electronico'] ?? '');
            if ($email !== '' && Validator::make(['email' => $email], ['email' => 'email'])->fails()) {
                $emails++;
                $email = '';
            }

            $unmergedOld = ($d['status_empleado'] ?? '') === '04';
            $active = ! $unmergedOld && (
                isset($cells[$currentPeriod])
                || (isset($staff2[$rfc]) && strtoupper($d['nombramiento'] ?? '') === 'D')
            );

            $this->rows[] = [
                'rfc' => $rfc,
                'name' => $name,
                'department' => $department,
                'email' => $email,
                'degree' => '',
                'active' => $active ? 'Sí' : 'No',
                'hired_on' => $this->hiredOn($d, $d2),
            ];

            foreach ($sources as $source) {
                $this->teacherFor[$source] = $rfc;
            }
            $this->cells[$rfc] = $cells;
            foreach ($sources as $source) {
                foreach ($rawKeys[$source] ?? [] as $period => $keys) {
                    $this->groupKeys[$rfc][$period] = [...($this->groupKeys[$rfc][$period] ?? []), ...$keys];
                }
            }
        }

        foreach ($raw as $rfc => $periodCells) {
            if (! isset($this->teacherFor[$rfc]) && (isset($staff[$rfc]) || isset($staff2[$rfc]))) {
                $this->unmigratedGroups += array_sum(array_map(fn (array $c): int => array_sum($c), $periodCells));
            }
        }
        $this->unmigratedGroups += $unknown;

        $this->aggregate(count($included), count($staff + $staff2), $emails, $noDepartment + $nameless, $unknown);
    }

    /**
     * @return array{0: array<string, array<string, array<string, int>>>, 1: list<string>} conteos por RFC, periodo
     *                                                                                     y materia, y los periodos que hay
     */
    private function readGroups(SiiExport $export): array
    {
        $raw = [];
        $periods = [];
        $keys = [];

        foreach ($export->reader(SiiFile::Grupos)->rows() as $row) {
            $periods[$row['periodo']] = true;
            $rfc = self::normalizeRfc($row['rfc']);

            if ($rfc === '') {
                $this->withoutRfc++;

                continue;
            }

            $keys[$rfc][$row['periodo']][self::groupKey($row['materia'], $row['grupo'])] = true;
            $raw[$rfc][$row['periodo']][$row['materia']] = ($raw[$rfc][$row['periodo']][$row['materia']] ?? 0) + 1;
        }

        $periods = array_map('strval', array_keys($periods));
        sort($periods);

        return [$raw, $periods, $keys];
    }

    /**
     * Un RFC de estatus 04 se funde con el único otro RFC incluido, de otro estatus, que comparte sus 10 primeros
     * caracteres (supuesto por confirmar). Sin pareja, o con varias, se migra como docente aparte con aviso.
     *
     * @param  list<string>  $included
     * @param  array<string, array<string, string>>  $staff
     * @return array<string, string> RFC viejo al nuevo
     */
    private function mergeChangedRfcs(array $included, array $staff): array
    {
        $merge = [];

        foreach ($included as $old) {
            if (($staff[$old]['status_empleado'] ?? '') !== '04') {
                continue;
            }

            $prefix = mb_substr($old, 0, 10);
            $candidates = mb_strlen($old) < 10 ? [] : array_values(array_filter(
                $included,
                fn (string $rfc): bool => $rfc !== $old && ($staff[$rfc]['status_empleado'] ?? '') !== '04' && mb_substr($rfc, 0, 10) === $prefix,
            ));

            if (count($candidates) === 1) {
                $merge[$old] = $candidates[0];

                continue;
            }

            $this->incidents[] = $this->warning($old, count($candidates) === 0
                ? "El RFC {$old} es de estatus 04 (cambio de RFC) y no hay RFC nuevo que coincida: se migra como docente inactivo (supuesto por confirmar)."
                : "El RFC {$old} es de estatus 04 (cambio de RFC) y coincide con varios RFC nuevos: se migra como docente inactivo (supuesto por confirmar).");
        }

        return $merge;
    }

    /**
     * @param  array<string, array<string, array<string, int>>>  $raw
     * @param  list<string>  $sources
     * @return array<string, array<string, int>>
     */
    private function mergeCells(array $raw, array $sources): array
    {
        $cells = [];

        foreach ($sources as $source) {
            foreach ($raw[$source] ?? [] as $period => $materias) {
                foreach ($materias as $materia => $count) {
                    $cells[$period][$materia] = ($cells[$period][$materia] ?? 0) + $count;
                }
            }
        }

        return $cells;
    }

    /**
     * @return array<string, string> clave de área de cada materia
     */
    private function materiaAreas(SiiExport $export): array
    {
        $areas = [];

        foreach ($export->reader(SiiFile::Materias)->rows() as $row) {
            $areas[$row['materia']] = $row['clave_area'];
        }

        return $areas;
    }

    /**
     * Los últimos 4 periodos regulares (terminan en 1 o 3) en que el docente tuvo grupos (R19).
     *
     * @param  list<string>  $periods
     * @return list<string>
     */
    private function ownLastRegularPeriods(array $periods): array
    {
        sort($periods);
        $regular = array_values(array_filter($periods, fn (string $p): bool => in_array(substr($p, -1), ['1', '3'], true)));

        return array_slice($regular, -self::REGULAR_PERIODS);
    }

    /**
     * @param  array<string, string>|null  $d
     * @param  array<string, string>|null  $d2
     */
    private function name(?array $d, ?array $d2): string
    {
        $join = fn (array $parts): string => SubjectName::title(implode(' ', array_filter($parts, fn (string $part): bool => trim($part) !== '')));
        $name = $d2 === null ? '' : $join([$d2['nombre_empleado'], $d2['apellidop'], $d2['apellidom']]);

        return $name !== '' ? $name : $join([$d['nombre_empleado'] ?? '', $d['apellidos_empleado'] ?? '']);
    }

    /**
     * @param  array<string, string>|null  $d
     * @param  array<string, string>|null  $d2
     * @param  array<string, array<string, int>>  $cells
     * @param  array<string, string>  $materiaArea
     * @param  array<string|int, string|null>  $departmentForArea
     */
    private function department(?array $d, ?array $d2, array $cells, array $materiaArea, array $departmentForArea): ?string
    {
        foreach ([$d2['id_unidad_organica'] ?? '', $d['clave_area'] ?? ''] as $area) {
            $code = $area === '' ? null : ($departmentForArea[$area] ?? null);
            if ($code !== null) {
                return $code;
            }
        }

        $counts = [];

        foreach ($this->ownLastRegularPeriods(array_map('strval', array_keys($cells))) as $period) {
            foreach ($cells[$period] ?? [] as $materia => $count) {
                $code = $departmentForArea[$materiaArea[(string) $materia] ?? ''] ?? null;
                if ($code !== null) {
                    $counts[$code] = ($counts[$code] ?? 0) + $count;
                }
            }
        }

        if ($counts === []) {
            return null;
        }

        ksort($counts);
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * `fecha_ingreso_institucion` si es una fecha válida; si no, `inicio_plantel` como año y quincena (`YYYYQQ`:
     * día 1 en quincena impar, 16 en par). Los comodines (`1900xx`, años fuera de 1950 a hoy, `676767`) quedan vacíos.
     *
     * @param  array<string, string>|null  $d
     * @param  array<string, string>|null  $d2
     */
    private function hiredOn(?array $d, ?array $d2): string
    {
        $raw = $d2['fecha_ingreso_institucion'] ?? '';

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $raw, $m) === 1 || preg_match('/^(\d{2})\/(\d{2})\/(\d{4})/', $raw, $m) === 1) {
            [$year, $month, $day] = str_contains($raw, '/') ? [$m[3], $m[2], $m[1]] : [$m[1], $m[2], $m[3]];

            if (checkdate((int) $month, (int) $day, (int) $year) && $this->plausibleYear((int) $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        if (preg_match('/^(\d{4})(\d{2})$/', $d['inicio_plantel'] ?? '', $m) === 1) {
            $year = (int) $m[1];
            $quincena = (int) $m[2];

            if ($this->plausibleYear($year) && $quincena >= 1 && $quincena <= 24) {
                return sprintf('%04d-%02d-%02d', $year, intdiv($quincena + 1, 2), $quincena % 2 === 1 ? 1 : 16);
            }
        }

        return '';
    }

    private function plausibleYear(int $year): bool
    {
        return $year >= 1950 && $year <= (int) date('Y');
    }

    private function aggregate(int $included, int $staffTotal, int $emails, int $skipped, int $unknown): void
    {
        $notTeachers = $staffTotal - $included;

        if ($notTeachers > 0) {
            $this->incidents[] = $this->warning('docentes', "{$notTeachers} personas del SII no son docentes (personal administrativo sin grupos): no se migran (D10).");
        }

        if ($skipped > 0) {
            $this->incidents[] = $this->warning('docentes', "{$skipped} docentes no se migran por falta de departamento o de nombre; sus grupos tampoco.");
        }

        if ($emails > 0) {
            $this->incidents[] = $this->warning('docentes', "{$emails} correos del SII no son válidos: esos docentes quedan sin correo (supuesto por confirmar).");
        }

        if ($unknown > 0) {
            $this->incidents[] = $this->warning('docentes', "{$unknown} grupos tienen un RFC que no está en los archivos de docentes: no se migran.");
        }

        $this->incidents[] = $this->warning('docentes', self::DEGREE_WARNING);
    }

    /**
     * @return array{level: 'warning', ref: string, message: string}
     */
    private function warning(string $ref, string $message): array
    {
        return ['level' => 'warning', 'ref' => $ref, 'message' => $message];
    }
}
