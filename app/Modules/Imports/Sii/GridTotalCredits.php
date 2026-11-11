<?php

namespace App\Modules\Imports\Sii;

/**
 * Los créditos totales de una retícula del SII (`carreras.creditos_totales`), con una sola regla para el paso `carreras`
 * (CareersStep, los créditos totales del plan) y la verificación (VerifySiiMigration, cuándo un alumno está completo en el
 * SII): solo cuenta un entero mayor que cero (vacío o en cero, la retícula no los tiene) y, si la retícula viene en varios
 * renglones con créditos distintos, quedan los mayores (supuesto por confirmar; el paso `carreras` avisa).
 */
final class GridTotalCredits
{
    /**
     * El valor como créditos totales, o null si no es un entero mayor que cero.
     */
    public static function parse(string $value): ?int
    {
        $value = trim($value);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Los créditos totales distintos de cada retícula, de menor a mayor; una retícula sin ninguno válido no aparece.
     *
     * @param  iterable<array<string, string>>  $rows  renglones de `carreras` con `carrera`, `reticula` y `creditos_totales`
     * @return array<string, list<int>> por retícula (`carrera/reticula`)
     */
    public static function byGrid(iterable $rows): array
    {
        $credits = [];

        foreach ($rows as $row) {
            $value = self::parse($row['creditos_totales'] ?? '');

            if ($value !== null) {
                $credits[trim($row['carrera']).'/'.trim($row['reticula'])][$value] = true;
            }
        }

        return array_map(function (array $values): array {
            $values = array_keys($values);
            sort($values);

            return $values;
        }, $credits);
    }

    /**
     * Los que quedan de una retícula con varios: los mayores.
     *
     * @param  non-empty-list<int>  $values
     */
    public static function kept(array $values): int
    {
        return max($values);
    }
}
