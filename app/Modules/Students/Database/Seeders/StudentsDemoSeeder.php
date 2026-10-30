<?php

namespace App\Modules\Students\Database\Seeders;

use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Models\Campus;
use App\Modules\Students\Actions\RefreshStudentProgress;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Alumnos y kárdex demo para probar las pantallas y el avance con datos verosímiles: por cada carrera que se ofrece
 * en un campus, `$studentsPerOffering` alumnos con su plan, su estatus y una trayectoria simulada periodo por
 * periodo (TrajectorySimulator) desde su ingreso hasta el periodo en curso, `20263` (Ago-Dic 2026).
 *
 * Lee el catálogo de la base (AcademicDemoSeeder): los planes con su retícula y su seriación. Cada carrera activa
 * da `N` alumnos, casi todos en su plan vigente; los primeros de cada una recorren los casos (egresado, bajas, sin
 * estatus, desactivado, plan viejo) para que haya de todo aun con pocos alumnos. Las carreras inactivas dan unos
 * pocos alumnos más, desactivados y en el plan sin retícula, sin kárdex. Los números de control son ficticios
 * (año de ingreso, 11 o 12 según el campus y consecutivo) y los nombres salen de listas; con la misma semilla
 * salen los mismos alumnos y el mismo kárdex.
 *
 * Inserta con `DB::table()` en bloques de 2 000 (el id lo pone el default `uuidv7()` de la base) y no duplica al
 * volver a sembrar (ignora números de control y kárdex repetidos). Al final recalcula `student_progress`. No corre
 * en producción.
 */
class StudentsDemoSeeder extends Seeder
{
    use RefusesProduction;

    /** Periodo en curso del kárdex de los alumnos demo (Ago-Dic 2026). */
    public const string CURRENT_TERM = '20263';

    private const int CHUNK = 2000;

    private const int INACTIVE_CAREER_STUDENTS = 3;

    private const array SCENARIOS = ['egresado', 'baja_temporal', 'baja_definitiva', 'sin_estatus', 'desactivado', 'plan_viejo', 'activo'];

    private const array CAMPUS_DIGITS = ['C1' => '11', 'C2' => '12'];

    private const array MALE_NAMES = ['José', 'Luis', 'Juan', 'Carlos', 'Miguel', 'Jorge', 'Francisco', 'Alejandro', 'Daniel', 'Diego', 'Ricardo', 'Fernando', 'Eduardo', 'Roberto', 'Javier', 'Sergio', 'Héctor', 'Óscar', 'Iván', 'Gael', 'Emiliano', 'Santiago', 'Mateo', 'Leonardo', 'Ángel', 'Adrián', 'Raúl', 'Julio', 'Armando', 'Cristian'];

    private const array FEMALE_NAMES = ['María', 'Guadalupe', 'Ana', 'Fernanda', 'Daniela', 'Valeria', 'Sofía', 'Camila', 'Alejandra', 'Paola', 'Karla', 'Andrea', 'Mariana', 'Gabriela', 'Jessica', 'Brenda', 'Citlali', 'Ximena', 'Regina', 'Abigail', 'Itzel', 'Yesenia', 'Lizeth', 'Nayeli', 'Rosa', 'Elena', 'Patricia', 'Claudia', 'Verónica', 'Dulce'];

    private const array SURNAMES = ['Hernández', 'García', 'Martínez', 'López', 'González', 'Pérez', 'Rodríguez', 'Sánchez', 'Ramírez', 'Cruz', 'Flores', 'Gómez', 'Morales', 'Vázquez', 'Jiménez', 'Reyes', 'Díaz', 'Torres', 'Gutiérrez', 'Ruiz', 'Mendoza', 'Aguilar', 'Ortiz', 'Moreno', 'Castillo', 'Romero', 'Álvarez', 'Chávez', 'Rivera', 'Juárez', 'Domínguez', 'Guerrero', 'Estrada', 'Ortega', 'Contreras', 'Soto', 'Delgado', 'Navarro', 'Rangel', 'Villa', 'Carrasco', 'Holguín', 'Terrazas', 'Márquez', 'Lozano', 'Salas', 'Meza', 'Barraza', 'Ponce', 'Sandoval'];

    /**
     * @param  array<string, int>  $studentsPerOffering  alumnos por cada carrera activa que se ofrece, por clave de campus
     * @param  int  $seed  semilla: la misma da los mismos alumnos y el mismo kárdex
     *
     * @throws RuntimeException en producción o si falta un campus
     */
    public function run(array $studentsPerOffering = ['C2' => 35], int $seed = 2027): void
    {
        $this->refuseProduction();

        $campuses = Campus::query()->whereIn('code', array_keys($studentsPerOffering))->pluck('id', 'code')->all();

        if (count($campuses) !== count($studentsPerOffering)) {
            throw new RuntimeException('Faltan campus de '.implode(', ', array_keys($studentsPerOffering)).': corre antes CampusSeeder.');
        }

        $random = new Randomizer(new Mt19937($seed));
        $simulator = new TrajectorySimulator($random);
        $catalog = $this->catalog();
        $termIds = DB::table('terms')->pluck('id', 'code')->all();
        $now = now();
        $counters = [];
        $offeringIndex = 0;
        $timing = ['simulation' => 0.0, 'students' => 0.0, 'kardex' => 0.0];
        $totals = ['students' => 0, 'kardex' => 0];
        $buffer = [];

        $flush = function () use (&$buffer, &$timing, &$totals, $now): void {
            $this->flush($buffer, $now, $timing, $totals);
            $buffer = [];
        };

        foreach ($studentsPerOffering as $campusCode => $perOffering) {
            $digits = self::CAMPUS_DIGITS[$campusCode] ?? sprintf('%02d', 13 + array_search($campusCode, array_keys($studentsPerOffering), true));

            foreach ($this->offerings($campuses[$campusCode]) as $offering) {
                $plans = $catalog['plansByCareer'][$offering->career_id] ?? [];
                $current = $this->currentPlan($plans, $catalog['grids']);
                $old = array_values(array_filter($plans, fn (object $plan): bool => ! $plan->is_active && isset($catalog['grids'][$plan->id])));
                $withoutGrid = array_values(array_filter($plans, fn (object $plan): bool => ! isset($catalog['grids'][$plan->id])));
                $active = $offering->career_active && $offering->is_active;

                if ($active && $current === null) {
                    continue;
                }

                $count = $active ? $perOffering : min($perOffering, self::INACTIVE_CAREER_STUDENTS);
                $offeringIndex++;

                for ($i = 0; $i < $count; $i++) {
                    $started = hrtime(true);
                    $blueprint = $active
                        ? $this->blueprint($random, $simulator, $i, $perOffering, $offeringIndex, $current, $old[0] ?? null, $catalog)
                        : $this->inactiveBlueprint($random, $i, $withoutGrid[0] ?? $plans[0] ?? null);

                    if ($blueprint === null) {
                        continue;
                    }

                    $yy = substr($blueprint['admitted'] ?? self::CURRENT_TERM, 2, 2);
                    $counters["{$yy}.{$digits}"] = ($counters["{$yy}.{$digits}"] ?? 0) + 1;

                    $buffer[] = [
                        'student' => [
                            'control_number' => sprintf('%s%s%04d', $yy, $digits, $counters["{$yy}.{$digits}"]),
                            'name' => $this->name($random),
                            'career_id' => $offering->career_id,
                            'study_plan_id' => $blueprint['plan']->id,
                            'campus_id' => $campuses[$campusCode],
                            'current_semester' => $blueprint['semester'],
                            'status' => $blueprint['status'],
                            'admitted_term_id' => isset($blueprint['admitted']) ? ($termIds[$blueprint['admitted']] ?? null) : null,
                            'is_active' => $blueprint['is_active'],
                        ],
                        'kardex' => $blueprint['kardex'],
                    ];
                    $timing['simulation'] += (hrtime(true) - $started) / 1e9;

                    if (count($buffer) >= self::CHUNK) {
                        $flush();
                    }
                }
            }
        }

        $flush();

        $started = hrtime(true);
        app(RefreshStudentProgress::class)->handle();
        $progress = (hrtime(true) - $started) / 1e9;

        $this->command?->info(sprintf(
            'Alumnos: %d (simulación %.1f s, inserción %.1f s). Kárdex: %d filas (inserción %.1f s). Avance recalculado en %.1f s.',
            $totals['students'], $timing['simulation'], $timing['students'], $totals['kardex'], $timing['kardex'], $progress,
        ));
    }

    /**
     * Los planes de cada carrera y la retícula de los planes que la tienen.
     *
     * @return array{plansByCareer: array<string, list<object>>, grids: array<string, array{semesters: array<int, list<string>>, requires: array<string, list<string>>, specialtyOf: array<string, string>, count: int}>, allSubjects: list<string>}
     */
    private function catalog(): array
    {
        $plansByCareer = DB::table('study_plans')->whereNull('deleted_at')->orderBy('code')->get(['id', 'career_id', 'code', 'is_active', 'valid_from', 'semesters_count'])->groupBy('career_id')->map(fn ($plans): array => $plans->all())->all();

        $grids = [];

        $placements = DB::table('study_plan_subjects')
            ->join('subjects', 'subjects.id', '=', 'study_plan_subjects.subject_id')
            ->whereNull('study_plan_subjects.deleted_at')
            ->whereNull('subjects.deleted_at')
            ->orderBy('study_plan_subjects.study_plan_id')
            ->orderBy('study_plan_subjects.semester')
            ->orderBy('subjects.code')
            ->get(['study_plan_subjects.study_plan_id', 'study_plan_subjects.subject_id', 'study_plan_subjects.semester', 'study_plan_subjects.specialty_id']);

        foreach ($placements as $row) {
            $grids[$row->study_plan_id]['semesters'][(int) $row->semester][] = $row->subject_id;
            $grids[$row->study_plan_id]['requires'] ??= [];
            $grids[$row->study_plan_id]['specialtyOf'] ??= [];
            $grids[$row->study_plan_id]['count'] = max($grids[$row->study_plan_id]['count'] ?? 0, (int) $row->semester);

            if ($row->specialty_id !== null) {
                $grids[$row->study_plan_id]['specialtyOf'][$row->subject_id] = $row->specialty_id;
            }
        }

        $seriation = DB::table('subject_prerequisites')
            ->whereNull('deleted_at')
            ->where('kind', 'seriacion')
            ->orderBy('prerequisite_subject_id')
            ->get(['study_plan_id', 'subject_id', 'prerequisite_subject_id']);

        foreach ($seriation as $row) {
            if (isset($grids[$row->study_plan_id])) {
                $grids[$row->study_plan_id]['requires'][$row->subject_id][] = $row->prerequisite_subject_id;
            }
        }

        return ['plansByCareer' => $plansByCareer, 'grids' => $grids, 'allSubjects' => array_values(array_unique(array_column($placements->all(), 'subject_id')))];
    }

    /**
     * Las carreras que se ofrecen en un campus, por clave de carrera.
     *
     * @return list<object>
     */
    private function offerings(string $campusId): array
    {
        return DB::table('career_campus')
            ->join('careers', 'careers.id', '=', 'career_campus.career_id')
            ->whereNull('career_campus.deleted_at')
            ->whereNull('careers.deleted_at')
            ->where('career_campus.campus_id', $campusId)
            ->orderBy('careers.code')
            ->get(['career_campus.career_id', 'career_campus.is_active', 'careers.is_active as career_active'])
            ->all();
    }

    /**
     * El plan vigente de una carrera: el activo con retícula más reciente.
     *
     * @param  list<object>  $plans
     * @param  array<string, mixed>  $grids
     */
    private function currentPlan(array $plans, array $grids): ?object
    {
        $candidates = array_values(array_filter($plans, fn (object $plan): bool => $plan->is_active && isset($grids[$plan->id])));

        usort($candidates, fn (object $a, object $b): int => [$b->valid_from ?? '', $b->code] <=> [$a->valid_from ?? '', $a->code]);

        return $candidates[0] ?? null;
    }

    /**
     * @param  array{plansByCareer: array<string, list<object>>, grids: array<string, array<string, mixed>>, allSubjects: list<string>}  $catalog
     * @return array{plan: object, admitted: string, status: ?string, is_active: bool, semester: ?int, kardex: list<array<string, mixed>>}|null
     */
    private function blueprint(Randomizer $random, TrajectorySimulator $simulator, int $index, int $perOffering, int $offeringIndex, object $current, ?object $old, array $catalog): ?array
    {
        $scenario = $this->scenario($random, $index, $perOffering, $offeringIndex);

        if ($scenario === 'plan_viejo' && $old === null) {
            $scenario = 'activo';
        }

        $plan = $scenario === 'plan_viejo' ? $old : $current;
        $isActive = $scenario !== 'desactivado';
        $status = match ($scenario) {
            'egresado', 'baja_temporal', 'baja_definitiva' => $scenario,
            'sin_estatus' => null,
            'plan_viejo' => ['egresado', 'egresado', 'baja_definitiva', 'activo', 'activo'][$random->getInt(0, 4)],
            'desactivado' => ['activo', 'baja_definitiva'][$random->getInt(0, 1)],
            default => 'activo',
        };

        $admitted = match (true) {
            $status === 'egresado' => $this->term($random, $scenario === 'plan_viejo' ? 2017 : 2018, $scenario === 'plan_viejo' ? 2018 : 2020),
            $scenario === 'plan_viejo' => $this->term($random, 2019, 2021),
            default => $this->term($random, 2022, 2026),
        };

        $grid = $catalog['grids'][$plan->id];
        $kardex = $simulator->simulate($grid, $admitted, self::CURRENT_TERM, [
            'ability' => 0.35 + 0.65 * sqrt($random->nextFloat()),
            'status' => $status,
            'ungraded' => $random->getInt(1, 10) === 1 ? 0.15 : 0.0,
        ]);

        $last = $kardex === [] ? $admitted : max(array_column($kardex, 'term_code'));

        if ($status !== 'egresado' && $this->failedSpecialCourse($kardex)) {
            $status = 'baja_definitiva';
        }

        if ($random->getInt(1, 100) <= 6) {
            $kardex = [...$kardex, ...$this->outsideThePlan($random, $kardex, $grid, $catalog['allSubjects'])];
        }

        $semester = $status === 'egresado' ? null : min(
            $grid['count'],
            max(1, $this->termIndex($status === 'activo' || $status === null ? self::CURRENT_TERM : $last) - $this->termIndex($admitted) + 1),
        );

        return ['plan' => $plan, 'admitted' => $admitted, 'status' => $status, 'is_active' => $isActive, 'semester' => $semester, 'kardex' => $kardex];
    }

    /**
     * Un alumno de una carrera que ya no se ofrece: desactivado, en el plan sin retícula y sin kárdex.
     *
     * @return array{plan: object, status: ?string, is_active: bool, semester: ?int, kardex: list<array<string, mixed>>}|null
     */
    private function inactiveBlueprint(Randomizer $random, int $index, ?object $plan): ?array
    {
        if ($plan === null) {
            return null;
        }

        return ['plan' => $plan, 'status' => $index === 0 ? null : 'baja_definitiva', 'is_active' => false, 'semester' => null, 'kardex' => []];
    }

    private function scenario(Randomizer $random, int $index, int $perOffering, int $offeringIndex): string
    {
        if ($index < min($perOffering, count(self::SCENARIOS))) {
            return self::SCENARIOS[($offeringIndex + $index) % count(self::SCENARIOS)];
        }

        return match (true) {
            ($roll = $random->getInt(1, 100)) <= 66 => 'activo',
            $roll <= 72 => 'baja_temporal',
            $roll <= 79 => 'baja_definitiva',
            $roll <= 89 => 'egresado',
            $roll <= 92 => 'sin_estatus',
            $roll <= 95 => 'desactivado',
            default => 'plan_viejo',
        };
    }

    /**
     * Un periodo de ingreso: Ene-Jun o, más seguido, Ago-Dic de un año entre `$from` y `$to`.
     */
    private function term(Randomizer $random, int $from, int $to): string
    {
        $term = ($random->getInt($from, $to)).($random->getInt(0, 9) < 6 ? 3 : 1);

        return $term > self::CURRENT_TERM ? '20261' : $term;
    }

    /**
     * Número de periodo regular (dos por año) para contar semestres.
     */
    private function termIndex(string $term): int
    {
        return (int) substr($term, 0, 4) * 2 + (substr($term, 4, 1) === '3' ? 1 : 0);
    }

    /**
     * @param  list<array{subject_id: string, term_code: string, grade: ?int, result: string, attempt: int}>  $kardex
     */
    private function failedSpecialCourse(array $kardex): bool
    {
        foreach ($kardex as $row) {
            if ($row['result'] === 'reprobada' && $row['attempt'] === 3) {
                return true;
            }
        }

        return false;
    }

    /**
     * Materias aprobadas que no son de la retícula del alumno (equivalencias, un plan anterior).
     *
     * @param  list<array{subject_id: string, term_code: string, grade: ?int, result: string, attempt: int}>  $kardex
     * @param  array<string, mixed>  $grid
     * @param  list<string>  $allSubjects
     * @return list<array{subject_id: string, term_code: string, grade: ?int, result: string, attempt: int}>
     */
    private function outsideThePlan(Randomizer $random, array $kardex, array $grid, array $allSubjects): array
    {
        $terms = array_values(array_unique(array_filter(array_column($kardex, 'term_code'), fn (string $term): bool => $term !== self::CURRENT_TERM && ! str_ends_with($term, '2'))));

        if ($terms === [] || $allSubjects === []) {
            return [];
        }

        $inPlan = array_fill_keys(array_merge(...array_values($grid['semesters'])), true);
        $rows = [];

        for ($i = $random->getInt(1, 2); $i > 0; $i--) {
            $subject = $allSubjects[$random->getInt(0, count($allSubjects) - 1)];

            if (! isset($inPlan[$subject]) && ! isset($rows[$subject])) {
                $rows[$subject] = ['subject_id' => $subject, 'term_code' => $terms[$random->getInt(0, count($terms) - 1)], 'grade' => $random->getInt(70, 100), 'result' => 'aprobada', 'attempt' => 1];
            }
        }

        return array_values($rows);
    }

    private function name(Randomizer $random): string
    {
        $names = $random->getInt(0, 1) === 0 ? self::MALE_NAMES : self::FEMALE_NAMES;

        return $names[$random->getInt(0, count($names) - 1)].' '
            .self::SURNAMES[$random->getInt(0, count(self::SURNAMES) - 1)].' '
            .self::SURNAMES[$random->getInt(0, count(self::SURNAMES) - 1)];
    }

    /**
     * Inserta un bloque de alumnos y su kárdex.
     *
     * @param  list<array{student: array<string, mixed>, kardex: list<array<string, mixed>>}>  $buffer
     * @param  array{simulation: float, students: float, kardex: float}  $timing
     * @param  array{students: int, kardex: int}  $totals
     */
    private function flush(array $buffer, mixed $now, array &$timing, array &$totals): void
    {
        if ($buffer === []) {
            return;
        }

        $started = hrtime(true);
        $students = array_map(fn (array $item): array => [...$item['student'], 'created_at' => $now, 'updated_at' => $now], $buffer);

        DB::table('students')->insertOrIgnore($students);

        $ids = DB::table('students')->whereIn('control_number', array_column($students, 'control_number'))->pluck('id', 'control_number')->all();
        $timing['students'] += (hrtime(true) - $started) / 1e9;
        $totals['students'] += count($students);

        $started = hrtime(true);
        $rows = [];

        foreach ($buffer as $item) {
            foreach ($item['kardex'] as $row) {
                $rows[] = [...$row, 'student_id' => $ids[$item['student']['control_number']], 'created_at' => $now, 'updated_at' => $now];
            }
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('kardex_records')->insertOrIgnore($chunk);
        }

        $timing['kardex'] += (hrtime(true) - $started) / 1e9;
        $totals['kardex'] += count($rows);
    }
}
