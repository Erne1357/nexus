<?php

namespace App\Modules\Solver\Engines\PhpHeuristic;

/**
 * Los juegos de días en que la heurística prueba un grupo (tarea 6.6; reglas-de-negocio.md, «Patrón de sesiones»):
 * sesiones de una hora, una por día en días distintos (PS-01) y a la misma hora todos los días (PS-02).
 *
 * - **El patrón** (PS-01, confirmado el 17 de noviembre de 2026): 4 horas de lunes a jueves, 5 de lunes a viernes y 6 de lunes
 *   a viernes más el sábado, si el campus trae sábado (supuesto por confirmar). Con otras horas, el mismo criterio
 *   extendido: los primeros días del campus, una hora por día (3 horas: lunes a miércoles; PS-03, supuesto por confirmar).
 * - **Otros días** (PS-04, supuesto por confirmar): si el patrón no cabe, los demás juegos de la misma cantidad de días,
 *   primero los que más se parecen al patrón, luego los que llevan sábado.
 * - **Dos horas el mismo día** (PS-04, supuesto por confirmar): si tampoco caben, menos días con el reparto más parejo (5
 *   horas en 4 días: un día de 2 horas seguidas), hasta 2 horas por día, o las que hagan falta si las horas no caben de
 *   otro modo en los días del campus. Así un docente que no puede un día, o un laboratorio prestado solo el martes,
 *   todavía dejan programar al grupo, y SC-02 lo cuenta.
 * - Más horas que días (7 horas de lunes a viernes): todos los días del campus, con las horas de sobra repartidas
 *   (PS-03).
 *
 * Cada opción es [día => unidades]. Los niveles van en orden: la heurística toma el mejor candidato del primer nivel
 * donde alguno cabe.
 */
final class SessionPattern
{
    /** El sábado: un juego de días sin sábado va antes que uno con sábado. */
    private const int SATURDAY = 6;

    /**
     * Los niveles de opciones para `units` sesiones en los días del campus: el patrón, los otros juegos con la misma
     * cantidad de días y, después, un nivel por cada cantidad menor de días.
     *
     * @param  list<int>  $campusDays
     * @return list<list<array<int, int>>>
     */
    public static function tiers(int $units, array $campusDays): array
    {
        $days = array_values(array_unique($campusDays));
        sort($days);
        $count = count($days);

        if ($units < 1 || $count === 0) {
            return [];
        }

        $most = min($units, $count);
        $perDay = max(2, intdiv($units + $count - 1, $count));
        $fewest = intdiv($units + $perDay - 1, $perDay);
        $tiers = [];

        for ($k = $most; $k >= $fewest; $k--) {
            $options = [];

            foreach (self::subsets($days, $k) as $subset) {
                $options[] = array_map(fn (array $spread): array => array_combine($subset, $spread), self::spreads($units, $k));
            }

            if ($k === $most) {
                $tiers[] = $options[0];
                $rest = array_merge(...array_slice($options, 1) ?: [[]]);

                if ($rest !== []) {
                    $tiers[] = $rest;
                }

                continue;
            }

            $tiers[] = array_merge(...$options);
        }

        return $tiers;
    }

    /**
     * Los juegos de `k` días, el más parecido a los primeros `k` días del campus primero y los que llevan sábado al
     * final.
     *
     * @param  list<int>  $days
     * @return list<list<int>>
     */
    private static function subsets(array $days, int $k): array
    {
        $first = array_slice($days, 0, $k);
        $subsets = self::combinations($days, $k);

        usort($subsets, fn (array $a, array $b): int => [count(array_diff($a, $first)), in_array(self::SATURDAY, $a, true), $a]
            <=> [count(array_diff($b, $first)), in_array(self::SATURDAY, $b, true), $b]);

        return $subsets;
    }

    /**
     * Los repartos de `units` en `k` días lo más parejos posible: cada día lleva lo mismo y las unidades de sobra van en
     * unos días, primero en los más tempranos.
     *
     * @return list<list<int>>
     */
    private static function spreads(int $units, int $k): array
    {
        $base = intdiv($units, $k);
        $extra = $units % $k;
        $spreads = [];

        foreach (self::combinations(range(0, $k - 1), $extra) as $positions) {
            $spread = array_fill(0, $k, $base);

            foreach ($positions as $position) {
                $spread[$position]++;
            }

            $spreads[] = $spread;
        }

        return $spreads;
    }

    /**
     * Las combinaciones de `k` elementos en orden lexicográfico.
     *
     * @template T
     *
     * @param  list<T>  $items
     * @return list<list<T>>
     */
    private static function combinations(array $items, int $k): array
    {
        if ($k === 0) {
            return [[]];
        }

        $result = [];

        for ($i = 0, $n = count($items); $i <= $n - $k; $i++) {
            foreach (self::combinations(array_slice($items, $i + 1), $k - 1) as $tail) {
                $result[] = [$items[$i], ...$tail];
            }
        }

        return $result;
    }
}
