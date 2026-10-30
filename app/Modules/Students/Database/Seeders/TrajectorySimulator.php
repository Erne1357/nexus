<?php

namespace App\Modules\Students\Database\Seeders;

use Random\Randomizer;

/**
 * Simula el kardex de un alumno periodo por periodo, sin base de datos, para los seeders demo y masivo. Sigue
 * las reglas de "Elegibilidad y demanda" y "Retícula del alumno": solo cursa una materia si aprobó antes toda su
 * seriación (los correquisitos no cuentan), puede cursar las de su semestre y las de hasta dos más, elige
 * especialidad al llegar a la primera y desde entonces no cursa las de otra, y cada materia tiene hasta tres
 * oportunidades (1 ordinaria, 2 repetición, 3 curso especial). Una baja no gasta oportunidad. Reprobar el curso
 * especial termina la trayectoria: el alumno se da de baja en automático.
 *
 * Los periodos son `AAAA1` (Ene-Jun) y `AAAA3` (Ago-Dic); en `AAAA2` (verano) algunos repiten lo reprobado. Las
 * materias del periodo en curso quedan «cursando» sin calificación; en un periodo anterior solo si el perfil pide
 * `ungraded` («sin calificar»). Con la misma semilla en el `Randomizer` da el mismo kardex.
 */
final class TrajectorySimulator
{
    private const int MAX_ATTEMPTS = 3;

    private const int SEMESTERS_AHEAD = 2;

    public function __construct(private readonly Randomizer $random) {}

    /**
     * @param  array{semesters: array<int, list<string>>, requires: array<string, list<string>>, specialtyOf: array<string, string>}  $grid  materias por semestre, su seriación y la especialidad de las que son de especialidad
     * @param  string  $admittedTerm  periodo de ingreso (`AAAA1` o `AAAA3`)
     * @param  string  $currentTerm  periodo en curso del campus
     * @param  array{ability: float, status: ?string, ungraded?: float}  $profile  `ability` de 0 a 1; `status` activo, baja_temporal, baja_definitiva, egresado o null; `ungraded` es la probabilidad de dejar sin calificar una materia de un periodo anterior
     * @return list<array{subject_id: string, term_code: string, grade: ?int, result: string, attempt: int}>
     */
    public function simulate(array $grid, string $admittedTerm, string $currentTerm, array $profile): array
    {
        $ability = $profile['ability'];
        $status = $profile['status'];
        $ungraded = $profile['ungraded'] ?? 0.0;
        $graduate = $status === 'egresado';

        $order = [];
        $semesterOf = [];

        foreach ($grid['semesters'] as $semester => $subjects) {
            foreach ($subjects as $subject) {
                $order[] = $subject;
                $semesterOf[$subject] = $semester;
            }
        }

        $terms = $this->terms($admittedTerm, $currentTerm);
        $lastRegular = $this->stopAfter($terms, $status);

        $passed = [];
        $spent = [];
        $retake = [];
        $chosen = null;
        $rows = [];
        $regularIndex = -1;

        foreach ($terms as $term) {
            $summer = str_ends_with($term, '2');
            $regularIndex += $summer ? 0 : 1;

            if (! $summer && $regularIndex > $lastRegular) {
                break;
            }

            if ($summer && $regularIndex >= $lastRegular) {
                break;
            }

            $taking = $summer
                ? $this->summerSubjects($retake)
                : $this->regularSubjects($order, $semesterOf, $regularIndex, $passed, $retake, $grid, $chosen, $graduate);

            $approvedNow = [];
            $stopped = false;

            foreach ($taking as $subject) {
                $attempt = ($spent[$subject] ?? 0) + 1;
                [$result, $grade] = $this->outcome($term === $currentTerm, $attempt, $ability, $graduate, $ungraded);

                $rows[] = ['subject_id' => $subject, 'term_code' => $term, 'grade' => $grade, 'result' => $result, 'attempt' => $attempt];

                if ($result === 'aprobada') {
                    $approvedNow[] = $subject;
                    unset($retake[$subject]);
                } elseif ($result !== 'baja') {
                    $spent[$subject] = $attempt;
                    $retake[$subject] = true;
                    $stopped = $stopped || ($result === 'reprobada' && $attempt === self::MAX_ATTEMPTS);
                }
            }

            foreach ($approvedNow as $subject) {
                $passed[$subject] = true;
            }

            if ($stopped || ($graduate && $this->complete($order, $passed, $chosen, $grid['specialtyOf']))) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Los periodos de `$from` a `$to`: Ene-Jun, verano y Ago-Dic de cada año.
     *
     * @return list<string>
     */
    private function terms(string $from, string $to): array
    {
        $terms = [];

        for ($year = (int) substr($from, 0, 4); $year <= (int) substr($to, 0, 4); $year++) {
            foreach ([1, 2, 3] as $slot) {
                $code = "{$year}{$slot}";

                if ($code >= $from && $code <= $to) {
                    $terms[] = $code;
                }
            }
        }

        return $terms;
    }

    /**
     * Cuántos periodos regulares (empezando en 0) cursa el alumno: los que lo llevan al periodo en curso si está
     * activo o sin estatus; si se dio de baja o egresó, se queda antes del periodo en curso.
     *
     * @param  list<string>  $terms
     */
    private function stopAfter(array $terms, ?string $status): int
    {
        $regular = count(array_filter($terms, fn (string $term): bool => ! str_ends_with($term, '2')));

        if ($status === 'baja_temporal' || $status === 'baja_definitiva') {
            return $this->random->getInt(0, max(0, $regular - 2));
        }

        if ($status === 'egresado') {
            return max(0, $regular - 2);
        }

        return $regular - 1;
    }

    /**
     * Lo que cursa en un periodo regular: primero lo que repite, luego lo que su seriación y su semestre le
     * permiten, hasta la carga del periodo.
     *
     * @param  list<string>  $order
     * @param  array<string, int>  $semesterOf
     * @param  array<string, true>  $passed
     * @param  array<string, true>  $retake
     * @param  array{semesters: array<int, list<string>>, requires: array<string, list<string>>, specialtyOf: array<string, string>}  $grid
     * @return list<string>
     */
    private function regularSubjects(array $order, array $semesterOf, int $regularIndex, array $passed, array $retake, array $grid, ?string &$chosen, bool $graduate): array
    {
        $reach = $regularIndex + 1 + self::SEMESTERS_AHEAD;
        $eligible = [];

        foreach ($order as $subject) {
            if (isset($passed[$subject]) || $semesterOf[$subject] > $reach || ! $this->seriationPassed($subject, $grid['requires'], $grid['semesters'], $passed)) {
                continue;
            }

            if (isset($retake[$subject]) && ! $graduate && $this->random->nextFloat() < 0.1) {
                continue;
            }

            $eligible[] = $subject;
        }

        if ($chosen === null) {
            $options = array_values(array_unique(array_filter(array_map(fn (string $subject): ?string => $grid['specialtyOf'][$subject] ?? null, $eligible))));

            if ($options !== []) {
                $chosen = $options[$this->random->getInt(0, count($options) - 1)];
            }
        }

        $eligible = array_values(array_filter($eligible, fn (string $subject): bool => ! isset($grid['specialtyOf'][$subject]) || $grid['specialtyOf'][$subject] === $chosen));

        usort($eligible, fn (string $a, string $b): int => [isset($retake[$b]), $semesterOf[$a]] <=> [isset($retake[$a]), $semesterOf[$b]]);

        $load = $graduate ? 9 : 5 + $this->random->getInt(0, 1);

        return array_slice($eligible, 0, $load);
    }

    /**
     * En verano, algunos repiten hasta dos materias que reprobaron.
     *
     * @param  array<string, true>  $retake
     * @return list<string>
     */
    private function summerSubjects(array $retake): array
    {
        if ($retake === [] || $this->random->nextFloat() >= 0.35) {
            return [];
        }

        return array_slice(array_keys($retake), 0, 2);
    }

    /**
     * @param  array<string, list<string>>  $requires
     * @param  array<int, list<string>>  $semesters
     * @param  array<string, true>  $passed
     */
    private function seriationPassed(string $subject, array $requires, array $semesters, array $passed): bool
    {
        foreach ($requires[$subject] ?? [] as $required) {
            if (! isset($passed[$required]) && $this->inGrid($required, $semesters)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, list<string>>  $semesters
     */
    private function inGrid(string $subject, array $semesters): bool
    {
        foreach ($semesters as $subjects) {
            if (in_array($subject, $subjects, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $order
     * @param  array<string, true>  $passed
     * @param  array<string, string>  $specialtyOf
     */
    private function complete(array $order, array $passed, ?string $chosen, array $specialtyOf): bool
    {
        foreach ($order as $subject) {
            if (! isset($passed[$subject]) && (! isset($specialtyOf[$subject]) || $specialtyOf[$subject] === $chosen)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resultado y calificación de una materia en un periodo. En el periodo en curso queda cursando; la
     * probabilidad de aprobar sube con `ability` y baja un poco en el curso especial.
     *
     * @return array{0: string, 1: ?int}
     */
    private function outcome(bool $inProgress, int $attempt, float $ability, bool $graduate, float $ungraded): array
    {
        if ($inProgress) {
            return ['cursando', null];
        }

        if (! $graduate && $this->random->nextFloat() < 0.03) {
            return ['baja', null];
        }

        if ($ungraded > 0 && $attempt < self::MAX_ATTEMPTS && $this->random->nextFloat() < $ungraded) {
            return ['cursando', null];
        }

        $chance = $graduate ? 1.0 : (0.5 + 0.5 * $ability) * ($attempt === self::MAX_ATTEMPTS ? 0.9 : 1.0);

        if ($this->random->nextFloat() < $chance) {
            return ['aprobada', min(100, 70 + (int) floor(30 * ($ability * 0.6 + $this->random->nextFloat() * 0.4)))];
        }

        return ['reprobada', $this->random->getInt(30, 69)];
    }
}
