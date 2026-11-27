<?php

namespace App\Modules\AcademicLoad\Rules;

/**
 * Qué tan bien proyectó la demanda contra lo que se cursó de verdad (DM-20 a DM-23). Clase pura: recibe por materia
 * lo proyectado, lo real y la línea base (los elegibles, la regla de R1).
 *
 * - DM-20: el «error medio por materia» es el error ponderado por alumnos, Σ|proyectado − real| / Σ real, y es null si
 *   Σ real = 0, sin dividir entre cero (supuesto por confirmar sobre la forma de promediar; el umbral está confirmado).
 * - DM-21: una materia con real 0 y proyectado 0 no cuenta en ningún promedio; con real 0 y proyectado mayor que 0 suma
 *   su proyectado al error y cuenta en n.
 * - DM-22: con error ponderado ≤ 15 % (`STRONG_THRESHOLD`, confirmado el 16 de noviembre de 2026) la proyección es
 *   sugerencia fuerte; arriba, referencia; sin error, sin medir.
 * - DM-23: `withinShare` es la fracción de materias con real > 0 cuyo error es de 15 % o menos de su real. `bias` y
 *   `meanAbsoluteError` se promedian entre las materias que cuentan (n, sin los pares 0/0).
 */
final class ForecastAccuracy
{
    /** El error ponderado a partir del cual la proyección deja de ser sugerencia fuerte (C2, confirmado). */
    public const float STRONG_THRESHOLD = 0.15;

    /** El mismo 15 %, en porcentaje entero, para comparar por materia sin errores de coma flotante. */
    private const int WITHIN_PERCENT = 15;

    /**
     * @param  iterable<array{projected: int, actual: int, baseline: int}>  $rows
     */
    public static function of(iterable $rows): ForecastAccuracyResult
    {
        $count = 0;
        $actual = 0;
        $projectedError = 0;
        $baselineError = 0;
        $signed = 0;
        $withinable = 0;
        $within = 0;

        foreach ($rows as $row) {
            if ($row['actual'] === 0 && $row['projected'] === 0) {
                continue;
            }

            $diff = $row['projected'] - $row['actual'];
            $count++;
            $actual += $row['actual'];
            $projectedError += abs($diff);
            $baselineError += abs($row['baseline'] - $row['actual']);
            $signed += $diff;

            if ($row['actual'] > 0) {
                $withinable++;
                $within += (int) (abs($diff) * 100 <= self::WITHIN_PERCENT * $row['actual']);
            }
        }

        return new ForecastAccuracyResult(
            weightedError: $actual === 0 ? null : $projectedError / $actual,
            baselineError: $actual === 0 ? null : $baselineError / $actual,
            meanAbsoluteError: $count === 0 ? 0.0 : $projectedError / $count,
            bias: $count === 0 ? 0.0 : $signed / $count,
            withinShare: $withinable === 0 ? null : $within / $withinable,
        );
    }
}
