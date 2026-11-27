<?php

namespace App\Modules\AcademicLoad\Rules;

use InvalidArgumentException;

/**
 * La hora del día como segundos desde la medianoche: `H:i` o `H:i:s`, horas de 0 a 23 con o sin cero inicial, minutos
 * y segundos de dos dígitos. Es el único lugar donde AcademicLoad compara horas, para no compararlas como texto
 * (`9:00` contra `10:00`). Métodos puros.
 */
final class ClockTime
{
    private const string PATTERN = '/^([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/D';

    public static function isValid(string $time): bool
    {
        return preg_match(self::PATTERN, $time) === 1;
    }

    /**
     * @throws InvalidArgumentException si no es una hora válida
     */
    public static function toSeconds(string $time): int
    {
        if (preg_match(self::PATTERN, $time, $parts) !== 1) {
            throw new InvalidArgumentException("Hora inválida: {$time}");
        }

        return (int) $parts[1] * 3600 + (int) $parts[2] * 60 + (int) ($parts[3] ?? 0);
    }
}
