<?php

namespace App\Modules\Staffing\Rules;

use InvalidArgumentException;

/**
 * Los semestres que cuentan como historial o evaluación de un docente (reglas-de-negocio.md, «Ranking docente
 * (desempate)», confirmado el 7 de noviembre de 2026): los últimos N semestres, Ene-Jun (`…1`) y Ago-Dic (`…3`),
 * antes del periodo que se rankea. Los veranos (`…2`) nunca cuentan. Puro.
 */
final readonly class RankingWindow
{
    /**
     * @return list<string> códigos SII, del más reciente al más antiguo
     */
    public static function semestersBefore(string $termCode, int $count): array
    {
        if (preg_match('/^[0-9]{4}[123]$/D', $termCode) !== 1) {
            throw new InvalidArgumentException("Código de periodo inválido: {$termCode}");
        }

        $year = (int) substr($termCode, 0, 4);
        $kind = (int) substr($termCode, 4);
        $codes = [];

        if ($kind === 2) {
            $kind = 3; // el semestre anterior al verano es el Ene-Jun del mismo año
        }

        while (count($codes) < $count) {
            if ($kind === 3) {
                $kind = 1;
            } else {
                $kind = 3;
                $year--;
            }

            $codes[] = $year.$kind;
        }

        return $codes;
    }
}
