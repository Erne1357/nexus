<?php

namespace App\Modules\Demand\Rules;

/**
 * Las tasas históricas de una ventana de periodos del mismo tipo, ya contadas por HistoricalRatesQuery (tarea 5.2):
 * por materia y campus, cuántos fueron elegibles al inicio de cada periodo, cuántos de ellos la tomaron (también por
 * tramo de distancia, GapBucket), cuántos alumnos la tomaron en total, cuántos la terminaron con calificación y cuántos
 * la reprobaron; y por carrera y campus, cuántos entraron en cada periodo.
 *
 * - DM-03: no inscripción = 1 − tomaron / elegibles; reprobación = reprobaron / calificados, sumando toda la ventana.
 *   La no inscripción también va por tramo de distancia, de los tramos que tuvieron elegibles (confirmado el 17 de
 *   noviembre de 2026).
 * - DM-04: una materia sin elegibles en la ventana usa la suma de las materias de su departamento en el mismo campus,
 *   marcada `fromDepartment`; sin eso, no hay tasa. Una materia con elegibles que nadie tomó en ningún campus
 *   (`neverTaken`, una materia nueva o que no se abrió) se proyecta con su historia (casi nadie se inscribe) y se marca
 *   para revisar: el coordinador la abre si la va a ofrecer (confirmado el 17 de noviembre de 2026). No entra en el
 *   promedio de su departamento, que mide materias que sí se abrieron (supuesto por confirmar). Una materia con
 *   elegibles pero sin calificados toma la reprobación del departamento, o cero.
 * - DM-05: el nuevo ingreso de una carrera en un campus es el promedio de los que entraron en los periodos de la
 *   ventana en que el campus tuvo alumnos; null si no tuvo ninguno.
 *
 * Sin campus (la columna única de una modalidad sin salones, MS5) suma todos los campus. Puro.
 *
 * @phpstan-type Counts array{eligible: int, took: int, takers?: int, graded: int, failed: int, terms: array<string, true>, gaps?: array<string, array{eligible: int, took: int}>}
 */
final class HistoricalRates
{
    /** @var array<string, array<string, Counts>> departamento → campus → suma */
    private array $byDepartment = [];

    /** @var array<string, true> materias con elegibles en la ventana que nadie tomó en ningún campus */
    private array $neverTaken = [];

    /**
     * @param  array<string, array<string, Counts>>  $bySubject  materia → campus → conteos
     * @param  array<string, string|null>  $departmentOf  materia → departamento
     * @param  array<string, array<string, array<string, int>>>  $intake  carrera → campus → periodo → alumnos que entraron
     * @param  array<string, array<string, true>>  $populated  campus → periodos en que tuvo alumnos
     */
    public function __construct(
        private readonly array $bySubject = [],
        private readonly array $departmentOf = [],
        private readonly array $intake = [],
        private readonly array $populated = [],
    ) {
        foreach ($bySubject as $subject => $campuses) {
            $eligible = array_sum(array_column($campuses, 'eligible'));
            $takers = array_sum(array_map(fn (array $counts): int => $counts['takers'] ?? $counts['took'], $campuses));

            if ($eligible > 0 && $takers === 0) {
                $this->neverTaken[$subject] = true;

                continue;
            }

            $department = $departmentOf[$subject] ?? null;

            if ($department === null) {
                continue;
            }

            foreach ($campuses as $campus => $counts) {
                $this->byDepartment[$department][$campus] = self::sum($this->byDepartment[$department][$campus] ?? null, $counts);
            }
        }
    }

    /**
     * Las tasas de una materia en un campus (o en todos, con null), o las de su departamento si no tiene historia
     * propia. Por tramo: el de la materia, si no el de la materia en todos los tramos; sin historia propia, el del
     * departamento, si no el del departamento en todos los tramos (SubjectRates::nonEnrollmentFor()).
     */
    public function rateFor(string $subjectId, ?string $campusId, ?string $departmentId): ?SubjectRates
    {
        $own = self::pick($this->bySubject[$subjectId] ?? [], $campusId);
        $department = $departmentId === null ? null : self::pick($this->byDepartment[$departmentId] ?? [], $campusId);

        if ($own !== null && $own['eligible'] > 0) {
            return new SubjectRates(
                nonEnrollment: 1 - $own['took'] / $own['eligible'],
                failure: self::failureOf($own) ?? ($department === null ? null : self::failureOf($department)) ?? 0.0,
                termsUsed: count($own['terms']),
                nonEnrollmentByGap: self::byGap($own),
            );
        }

        if ($department === null || $department['eligible'] === 0) {
            return null;
        }

        return new SubjectRates(
            nonEnrollment: 1 - $department['took'] / $department['eligible'],
            failure: self::failureOf($department) ?? 0.0,
            termsUsed: count($department['terms']),
            fromDepartment: true,
            nonEnrollmentByGap: self::byGap($department),
        );
    }

    /**
     * Si la materia tuvo elegibles en la ventana y nadie la tomó en ningún campus (DM-04): se proyecta con su historia
     * y se marca para revisar.
     */
    public function neverTaken(string $subjectId): bool
    {
        return isset($this->neverTaken[$subjectId]);
    }

    /**
     * El nuevo ingreso histórico de una carrera en un campus (o en todos, con null).
     */
    public function newStudentsFor(string $careerId, ?string $campusId): ?int
    {
        $campuses = $campusId === null ? array_keys($this->populated) : [$campusId];
        $terms = [];
        $students = 0;

        foreach ($campuses as $campus) {
            $terms += $this->populated[$campus] ?? [];
            $students += array_sum($this->intake[$careerId][$campus] ?? []);
        }

        return $terms === [] ? null : (int) round($students / count($terms));
    }

    /**
     * La no inscripción de cada tramo con elegibles.
     *
     * @param  Counts  $counts
     * @return array<string, float>
     */
    private static function byGap(array $counts): array
    {
        $rates = [];

        foreach ($counts['gaps'] ?? [] as $bucket => $gap) {
            if ($gap['eligible'] > 0) {
                $rates[$bucket] = 1 - $gap['took'] / $gap['eligible'];
            }
        }

        return $rates;
    }

    /**
     * @param  array<string, Counts>  $byCampus
     * @return Counts|null
     */
    private static function pick(array $byCampus, ?string $campusId): ?array
    {
        if ($campusId !== null) {
            return $byCampus[$campusId] ?? null;
        }

        $total = null;

        foreach ($byCampus as $counts) {
            $total = self::sum($total, $counts);
        }

        return $total;
    }

    /**
     * @param  Counts|null  $total
     * @param  Counts  $counts
     * @return Counts
     */
    private static function sum(?array $total, array $counts): array
    {
        $gaps = $total['gaps'] ?? [];

        foreach ($counts['gaps'] ?? [] as $bucket => $gap) {
            $gaps[$bucket] = [
                'eligible' => ($gaps[$bucket]['eligible'] ?? 0) + $gap['eligible'],
                'took' => ($gaps[$bucket]['took'] ?? 0) + $gap['took'],
            ];
        }

        return [
            'eligible' => ($total['eligible'] ?? 0) + $counts['eligible'],
            'took' => ($total['took'] ?? 0) + $counts['took'],
            'takers' => ($total['takers'] ?? 0) + ($counts['takers'] ?? $counts['took']),
            'graded' => ($total['graded'] ?? 0) + $counts['graded'],
            'failed' => ($total['failed'] ?? 0) + $counts['failed'],
            'terms' => ($total['terms'] ?? []) + $counts['terms'],
            'gaps' => $gaps,
        ];
    }

    /**
     * @param  array{graded: int, failed: int}  $counts
     */
    private static function failureOf(array $counts): ?float
    {
        return $counts['graded'] > 0 ? (float) $counts['failed'] / $counts['graded'] : null;
    }
}
