<?php

namespace App\Modules\Scheduling\Validation;

use App\Modules\Core\Support\WeekTime;

/**
 * Horas del día como minutos desde la medianoche, para comparar rangos rápido, y su forma para los mensajes.
 */
final class Minutes
{
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
     * La hora para leerla, sin cero a la izquierda: 420 → «7:00». Es la forma de todo texto que ve una persona
     * (mensajes del validador, de las Actions y de la bitácora, y la semana del editor); `format()` queda para los
     * datos (`related`, campos de formularios, llaves).
     */
    public static function label(int $minutes): string
    {
        return WeekTime::clock($minutes);
    }

    /**
     * label() de una hora guardada (`HH:MM:SS` o `HH:MM`): «07:30:00» → «7:30».
     */
    public static function labelOf(string $time): string
    {
        return WeekTime::clock($time);
    }

    /**
     * Una hora guardada (`HH:MM:SS` o `HH:MM`) como `HH:MM`, para datos (para leerla, labelOf()).
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
        return WeekTime::day($day);
    }

    /**
     * El nombre del día para mostrarlo solo: «Lunes»; corto, «Lun» (la única capitalización de los días).
     */
    public static function dayLabel(int $day, bool $short = false): string
    {
        $name = mb_convert_case(self::dayName($day), MB_CASE_TITLE);

        return $short ? mb_substr($name, 0, 3) : $name;
    }

    /**
     * Si [startA, endA) y [startB, endB) se empalman: dos horas contiguas no.
     */
    public static function overlap(int $startA, int $endA, int $startB, int $endB): bool
    {
        return $startA < $endB && $startB < $endA;
    }
}
