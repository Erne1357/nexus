<?php

namespace App\Modules\Imports\Sii\Steps;

use App\Modules\Imports\Sii\SiiBatch;
use App\Modules\Imports\Sii\SiiFile;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\SiiStep;
use App\Modules\Imports\Sii\SiiStepOutOfOrder;
use App\Modules\Imports\Sii\SubjectName;
use App\Modules\Imports\Sii\SubjectPlacements;

/**
 * Materias, especialidades y retícula (D8, D15, D16, D18; reglas-de-negocio.md "Reglas de datos"). Cada renglón de
 * `materias_carreras` es el lugar de una materia del SII en una retícula del SII; la retícula se lleva a su plan de
 * Nexus con `plan_for` (las excluidas del curado solo dejan un aviso por retícula) y la materia se une por su clave
 * oficial del TecNM (SubjectPlacements). Los renglones con estatus `N` (ya no se ofrecen) no entran a la retícula: van a
 * «Por validar», para no perder el kárdex de quien los aprobó (supuesto por confirmar, 10 de noviembre de 2026).
 * Nexus nunca decide un semestre (D18, confirmado el 10 de noviembre de 2026): un lugar sin semestre en el SII va a «Por
 * validar»; en una retícula viva, con el semestre sugerido de su misma carrera, y en una histórica (`historical_plans`,
 * del paso de carreras), tal cual, sin sugerencia.
 * El orden dentro del semestre (D21, confirmado el 5 de noviembre de 2026) sale de `renglon` de la variante que se
 * queda con el lugar (la presencial al fundir); 0 o vacío queda vacío y la materia va al final, por clave.
 *
 * De cada materia de Nexus: el nombre más frecuente entre sus claves del SII, normalizado (SubjectPlacements y
 * SubjectName, R12); el tipo por
 * `tipo_materia` (1 básica, 3 especialidad, 2 y 4 complementaria, supuesto por confirmar), salvo Residencia
 * Profesional y Servicio Social, que se reconocen por su nombre; el departamento por `department_for_area` de su
 * área y, si ninguna de sus claves del SII tiene área con departamento, el de la carrera de su primer plan, con
 * advertencia (D16). Cuando una clave TecNM junta claves del SII de varios departamentos o tipos, quedan el primer
 * departamento y el primer tipo, y se avisa (supuesto por confirmar). La especialidad es la clave del SII con el
 * nombre de `especialidades`; si no está ahí, solo la clave, y se avisa.
 *
 * Entrega tres lotes, en este orden: `subjects` (sin `sii_code`), `pending_placements` (los lugares sin semestre o
 * con estatus `N`, D18 y D19: la materia se da de alta y el lugar queda en «Por validar», con la retícula del SII de la
 * que salió en `sii_grid` y el semestre sugerido en `suggested_semester`, vacío sin sugerencia) y `subject_aliases`,
 * con cada clave del SII de cada lugar emitido o pendiente,
 * también las que se unieron en una clave TecNM. Recuerda `subject_for`: clave del SII →
 * clave de materia de Nexus, para los pasos de grupos y kárdex (las claves numéricas quedan como llaves int de
 * PHP: búscalas con `$map[$clave] ?? null`), y `subject_for_plan`: plan de Nexus → clave del SII → materia de
 * Nexus, para que el kárdex lleve la clave de cada alumno a la materia de su retícula y la seriación (SeriationStep), las
 * claves de cada requisito a las materias del plan.
 */
final class SubjectsStep implements SiiStep
{
    /** @var array<string, string> */
    private const array KINDS = ['1' => 'basica', '2' => 'complementaria', '3' => 'especialidad', '4' => 'complementaria'];

    /** @var array<string, string> */
    private const array KINDS_BY_NAME = ['RESIDENCIA PROFESIONAL' => 'residencia', 'SERVICIO SOCIAL' => 'servicio_social'];

    public function name(): string
    {
        return 'materias';
    }

    public function batches(SiiMigration $migration): iterable
    {
        $planFor = $this->map($migration, 'plan_for');
        $versions = $this->map($migration, 'plan_version');
        $departmentForArea = $this->map($migration, 'department_for_area');
        $historical = $this->map($migration, 'historical_plans');
        $catalog = $this->catalog($migration);

        $incidents = [];
        $placements = new SubjectPlacements($catalog, $versions, $historical);
        $grid = $placements->grid($this->placements($migration, $planFor, $catalog, $incidents));
        $incidents = [...$incidents, ...$placements->incidents()];

        $pending = $placements->pending();
        $rows = $this->subjectRows($migration, [...$grid, ...$pending], $catalog, $departmentForArea, $incidents);
        [$subjectFor, $aliases, $aliasIncidents, $subjectForPlan] = $placements->aliases($grid, $pending);
        $migration->remember('subject_for', fn (): array => $subjectFor);
        $migration->remember('subject_for_plan', fn (): array => $subjectForPlan);

        yield new SiiBatch('subjects', 'materias.csv', array_values(array_diff_key($rows, $pending)), incidents: $incidents);
        yield new SiiBatch('pending_placements', 'materias_pendientes.csv', array_values(array_map(
            fn (string $ref): array => [
                ...array_diff_key($rows[$ref], ['semester' => true, 'sii_code' => true]),
                'sii_grid' => $pending[$ref]['key'],
                'suggested_semester' => $pending[$ref]['suggested'] === null ? '' : (string) $pending[$ref]['suggested'],
            ],
            array_keys(array_intersect_key($pending, $rows)),
        )));
        yield new SiiBatch('subject_aliases', 'claves_materias.csv', $aliases, incidents: $aliasIncidents);
    }

    /**
     * Un mapa que armó un paso anterior de esta corrida.
     *
     * @return array<string|int, mixed>
     *
     * @throws SiiStepOutOfOrder si ese paso no corrió antes (por ejemplo, con `--only materias`)
     */
    private function map(SiiMigration $migration, string $key): array
    {
        return $migration->remember($key, fn () => throw new SiiStepOutOfOrder($this->name(), $key, ['organigrama', 'carreras']));
    }

    /**
     * El catálogo de materias del SII por clave.
     *
     * @return array<string, array{name: string, type: string, area: string}>
     */
    private function catalog(SiiMigration $migration): array
    {
        $catalog = [];

        foreach ($migration->export()->reader(SiiFile::Materias)->rows() as $row) {
            $catalog[trim($row['materia'])] ??= [
                'name' => trim($row['nombre_completo_materia']),
                'type' => trim($row['tipo_materia']),
                'area' => trim($row['clave_area']),
            ];
        }

        return $catalog;
    }

    /**
     * Los lugares de `materias_carreras` que se migran, con su plan de Nexus y su clave TecNM si la tiene; `retired`
     * marca los de estatus `N`. Avisa una vez por retícula excluida; un renglón cuya materia no está en el catálogo es
     * error.
     *
     * @param  array<string, array{plan: string, career: string, modality: string}>  $planFor
     * @param  array<string, array{name: string, type: string, area: string}>  $catalog
     * @param  list<array{level: 'error'|'warning', ref: string, message: string}>  $incidents
     * @return list<array{plan: string, career: string, modality: string, key: string, materia: string, tecnm: string|null, credits: string, theory: string, practice: string, semester: int, specialty: string, position: int, retired: bool}>
     */
    private function placements(SiiMigration $migration, array $planFor, array $catalog, array &$incidents): array
    {
        $tecnm = $this->tecnmKeys($migration, $incidents);
        $excluded = [];
        $placements = [];

        foreach ($migration->export()->reader(SiiFile::MateriasCarreras)->rows() as $row) {
            $key = trim($row['carrera']).'/'.trim($row['reticula']);
            $materia = trim($row['materia']);

            if (! isset($planFor[$key])) {
                $excluded[$key] = ($excluded[$key] ?? 0) + 1;

                continue;
            }

            $plan = $planFor[$key]['plan'];

            if (! isset($catalog[$materia])) {
                $incidents[] = ['level' => 'error', 'ref' => "{$plan}/{$materia}", 'message' => "La materia {$materia} de la retícula {$key} no está en el catálogo de materias del SII: no se migra."];

                continue;
            }

            $semester = trim($row['semestre_reticula']);
            $line = trim($row['renglon']);

            $placements[] = [
                'plan' => $plan,
                'career' => $planFor[$key]['career'],
                'modality' => $planFor[$key]['modality'],
                'key' => $key,
                'materia' => $materia,
                'tecnm' => $tecnm["{$key}/{$materia}"] ?? null,
                'credits' => $this->number($row['creditos_materia']),
                'theory' => $this->number($row['horas_teoricas']),
                'practice' => $this->number($row['horas_practicas']),
                'semester' => ctype_digit($semester) ? (int) $semester : 0,
                'specialty' => trim($row['especialidad']),
                'position' => ctype_digit($line) && (int) $line > 0 ? (int) $line : 0,
                'retired' => strtoupper(trim($row['estatus_materia_carrera'])) === 'N',
            ];
        }

        ksort($excluded, SORT_STRING);

        foreach ($excluded as $key => $count) {
            $incidents[] = $this->warning((string) $key, "Retícula {$key} excluida del curado: ".($count === 1 ? '1 lugar' : "{$count} lugares").' de materias no se migran.');
        }

        return $placements;
    }

    /**
     * La clave TecNM de cada lugar, por `"{carrera}/{retícula}/{materia}"`. Si el SII trae varias para el mismo lugar,
     * queda la menor y se avisa. Una «clave TecNM» igual a la clave del SII del lugar no es clave TecNM: el lugar queda
     * sin ella y se avisa una vez por retícula (supuesto por confirmar).
     *
     * @param  list<array{level: 'error'|'warning', ref: string, message: string}>  $incidents
     * @return array<string, string>
     */
    private function tecnmKeys(SiiMigration $migration, array &$incidents): array
    {
        $found = [];
        $selfKeyed = [];

        foreach ($migration->export()->reader(SiiFile::MateriasClavesTecnm)->rows() as $row) {
            $code = mb_strtoupper(trim($row['clave_tecnm']));
            $grid = trim($row['carrera']).'/'.trim($row['reticula']);
            $materia = trim($row['materia']);

            if ($code !== '' && $code === mb_strtoupper($materia)) {
                $selfKeyed[$grid][$materia] = true;
            } elseif ($code !== '') {
                $found["{$grid}/{$materia}"][$code] = true;
            }
        }

        ksort($selfKeyed, SORT_STRING);

        foreach ($selfKeyed as $grid => $materias) {
            $materias = array_map('strval', array_keys($materias));
            sort($materias, SORT_STRING);
            $count = count($materias);
            $shown = implode(', ', array_slice($materias, 0, 5)).($count > 5 ? ' y '.($count - 5).' más' : '');
            $incidents[] = $this->warning((string) $grid, "La retícula {$grid} del SII trae como clave TecNM su propia clave del SII en {$count} ".($count === 1 ? 'materia' : 'materias')." ({$shown}): no cuentan como clave TecNM (supuesto por confirmar).");
        }

        $keys = [];
        ksort($found, SORT_STRING);

        foreach ($found as $place => $codes) {
            $codes = array_map('strval', array_keys($codes));
            sort($codes, SORT_STRING);
            $keys[(string) $place] = $codes[0];

            if (count($codes) > 1) {
                $incidents[] = $this->warning((string) $place, "El SII trae varias claves TecNM para la materia del lugar {$place} (".implode(', ', $codes)."): queda {$codes[0]}.");
            }
        }

        return $keys;
    }

    /**
     * Un número entero del SII como texto (`5`, `5.00`); otro valor pasa igual y la plantilla lo reporta.
     */
    private function number(string $value): string
    {
        $value = trim($value);

        return preg_match('/^\d+(?:[.,]0+)?$/', $value) === 1 ? (string) (int) $value : $value;
    }

    /**
     * Un renglón de la plantilla `subjects` por lugar en la retícula, con el departamento y el tipo resueltos por
     * materia de Nexus.
     *
     * @param  array<string, array{plan: string, career: string, materia: string, code: string, name: string, credits: string, theory: string, practice: string, semester: int, specialty: string, position: int, materias: list<string>}>  $grid
     * @param  array<string, array{name: string, type: string, area: string}>  $catalog
     * @param  array<string|int, string|null>  $departmentForArea
     * @param  list<array{level: 'error'|'warning', ref: string, message: string}>  $incidents
     * @return array<string, array<string, string>> por `"{plan}/{clave}"`
     */
    private function subjectRows(SiiMigration $migration, array $grid, array $catalog, array $departmentForArea, array &$incidents): array
    {
        $careerDepartments = [];
        foreach ($migration->curated('carreras') as $row) {
            $careerDepartments[trim($row['carrera'])] = trim($row['departamento']);
        }

        $specialties = $this->specialtyNames($migration);
        $byCode = [];
        foreach ($grid as $placement) {
            $byCode[$placement['code']][] = $placement;
        }

        $subjects = [];
        foreach ($byCode as $code => $placements) {
            $subjects[(string) $code] = [
                'department' => $this->department((string) $code, $placements, $catalog, $departmentForArea, $careerDepartments, $incidents),
                'kind' => $this->kind((string) $code, $placements, $catalog, $incidents),
            ];
        }

        $rows = [];
        $missingSpecialties = [];

        foreach ($grid as $ref => $placement) {
            $specialty = $placement['specialty'];

            if ($specialty !== '' && ! isset($specialties[$specialty]) && ! isset($missingSpecialties[$placement['plan'].'/'.$specialty])) {
                $missingSpecialties[$placement['plan'].'/'.$specialty] = true;
                $incidents[] = $this->warning($placement['plan'].'/'.$specialty, "La especialidad {$specialty} del plan {$placement['plan']} no está en el catálogo de especialidades del SII: se crea con su clave como nombre.");
            }

            $rows[(string) $ref] = [
                'plan' => $placement['plan'],
                'code' => $placement['code'],
                'name' => $placement['name'],
                'semester' => (string) $placement['semester'],
                'theory_hours' => $placement['theory'],
                'practice_hours' => $placement['practice'],
                'credits' => $placement['credits'],
                'department' => $subjects[$placement['code']]['department'],
                'specialty' => $specialty,
                'specialty_name' => $specialty === '' ? '' : ($specialties[$specialty] ?? ''),
                'sii_code' => '',
                'position' => $placement['position'] > 0 ? (string) $placement['position'] : '',
                'kind' => $subjects[$placement['code']]['kind'],
            ];
        }

        return $rows;
    }

    /**
     * El nombre de cada especialidad del SII, por su clave (única en todo el archivo).
     *
     * @return array<string, string>
     */
    private function specialtyNames(SiiMigration $migration): array
    {
        $names = [];

        foreach ($migration->export()->reader(SiiFile::Especialidades)->rows() as $row) {
            $names[trim($row['especialidad'])] ??= trim((string) preg_replace('/\s+/u', ' ', $row['nombre_especialidad']));
        }

        return $names;
    }

    /**
     * El departamento de una materia de Nexus (D16): el de `department_for_area` del área de sus claves del SII; si
     * ninguna tiene, el de la carrera de su primer plan, con advertencia. Si sus claves vienen de departamentos
     * distintos, queda el primero y avisa (supuesto por confirmar).
     *
     * @param  list<array{plan: string, career: string, materias: list<string>}>  $placements
     * @param  array<string, array{name: string, type: string, area: string}>  $catalog
     * @param  array<string|int, string|null>  $departmentForArea
     * @param  array<string, string>  $careerDepartments
     * @param  list<array{level: 'error'|'warning', ref: string, message: string}>  $incidents
     */
    private function department(string $code, array $placements, array $catalog, array $departmentForArea, array $careerDepartments, array &$incidents): string
    {
        $found = [];
        $areas = [];

        foreach ($placements as $placement) {
            foreach ($placement['materias'] as $materia) {
                $area = $catalog[$materia]['area'];
                $department = $departmentForArea[$area] ?? null;

                if ($department === null || $department === '') {
                    $areas[$area === '' ? 'vacía' : $area] = true;
                } else {
                    $found[(string) $department] = true;
                }
            }
        }

        if ($found !== []) {
            $departments = array_map('strval', array_keys($found));

            if (count($departments) > 1) {
                $incidents[] = $this->warning($code, "La materia {$code} junta claves del SII de departamentos distintos (".implode(', ', $departments)."): queda en {$departments[0]} (supuesto por confirmar).");
            }

            return $departments[0];
        }

        $career = $placements[0]['career'];
        $department = $careerDepartments[$career] ?? '';
        $incidents[] = $this->warning($code, "La materia {$code} no tiene departamento en el SII (área ".implode(', ', array_map('strval', array_keys($areas)))."): toma el {$department} de la carrera {$career} (D16).");

        return $department;
    }

    /**
     * El tipo de una materia de Nexus: el de su primera clave del SII que tiene tipo (por nombre, Residencia
     * Profesional y Servicio Social; si no, por `tipo_materia`; otro valor queda sin tipo). Si sus claves traen tipos
     * distintos, avisa (supuesto por confirmar).
     *
     * @param  list<array{materias: list<string>}>  $placements
     * @param  array<string, array{name: string, type: string, area: string}>  $catalog
     * @param  list<array{level: 'error'|'warning', ref: string, message: string}>  $incidents
     */
    private function kind(string $code, array $placements, array $catalog, array &$incidents): string
    {
        $kinds = [];

        foreach ($placements as $placement) {
            foreach ($placement['materias'] as $materia) {
                $kind = self::KINDS_BY_NAME[SubjectName::key($catalog[$materia]['name'])] ?? self::KINDS[$catalog[$materia]['type']] ?? null;

                if ($kind !== null) {
                    $kinds[$kind] = true;
                }
            }
        }

        $kinds = array_keys($kinds);

        if (count($kinds) > 1) {
            $incidents[] = $this->warning($code, "La materia {$code} junta claves del SII de tipos distintos (".implode(', ', $kinds)."): queda {$kinds[0]} (supuesto por confirmar).");
        }

        return $kinds[0] ?? '';
    }

    /**
     * @return array{level: 'warning', ref: string, message: string}
     */
    private function warning(string $ref, string $message): array
    {
        return ['level' => 'warning', 'ref' => $ref, 'message' => $message];
    }
}
