<?php

namespace App\Modules\Scheduling\Validation;

/**
 * Horas del día como minutos desde la medianoche, para comparar rangos rápido, y su forma para los mensajes.
 */
final class Minutes
{
    private const array DAY_NAMES = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado'];

    /**
     * Los minutos de `HH:MM` o `HH:MM:SS`.
     */
    public static function of(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }

    /**
     * `HH:MM` de unos minutos.
     */
    public static function format(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * Una hora guardada (`HH:MM:SS` o `HH:MM`) como `HH:MM`, para mostrarla.
     */
    public static function clock(string $time): string
    {
        return self::format(self::of($time));
    }

    /**
     * El nombre del día (1 = lunes a 6 = sábado).
     */
    public static function dayName(int $day): string
    {
        return self::DAY_NAMES[$day] ?? (string) $day;
    }

    /**
     * Si [startA, endA) y [startB, endB) se empalman: dos horas contiguas no.
     */
    public static function overlap(int $startA, int $endA, int $startB, int $endB): bool
    {
        return $startA < $endB && $startB < $endA;
    }
}
