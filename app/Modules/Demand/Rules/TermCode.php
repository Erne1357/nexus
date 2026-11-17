<?php

namespace App\Modules\Demand\Rules;

use InvalidArgumentException;

/**
 * Cuentas con los códigos de periodo del SII (`AAAAT`: T = 1 Ene-Jun, 2 Verano, 3 Ago-Dic) para la proyección. Los
 * semestres ordinarios son Ene-Jun y Ago-Dic. El historial y las tasas se comparan con periodos del mismo tipo
 * (confirmado el 10 de noviembre de 2026), y la proyección no calcula verano (supuesto por confirmar).
 */
final class TermCode
{
    /**
     * @throws InvalidArgumentException si el código no es `AAAAT` con T en 1, 2 o 3
     */
    public static function assertValid(string $code): void
    {
        if (preg_match('/^\d{4}[123]$/', $code) !== 1) {
            throw new InvalidArgumentException("Código de periodo inválido: {$code}");
        }
    }

    /**
     * Ene-Jun o Ago-Dic; verano no.
     */
    public static function isRegular(string $code): bool
    {
        self::assertValid($code);

        return ! str_ends_with($code, '2');
    }

    /**
     * Los `$terms` periodos del mismo tipo justo antes del objetivo, el más reciente primero: de `20271`, con 4,
     * `20261`, `20251`, `20241` y `20231`.
     *
     * @return list<string>
     */
    public static function sameTypeBefore(string $target, int $terms): array
    {
        self::assertValid($target);

        $year = (int) substr($target, 0, 4);
        $type = substr($target, 4);
        $codes = [];

        for ($back = 1; $back <= $terms; $back++) {
            $codes[] = sprintf('%04d', $year - $back).$type;
        }

        return $codes;
    }

    /**
     * El semestre ordinario anterior: de `20271`, `20263`; de `20263` y de `20262`, `20261`.
     */
    public static function previousRegular(string $code): string
    {
        self::assertValid($code);

        $year = (int) substr($code, 0, 4);

        return str_ends_with($code, '1') ? sprintf('%04d3', $year - 1) : sprintf('%04d1', $year);
    }
}
