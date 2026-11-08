<?php

namespace App\Modules\Core\Support;

/**
 * Cómo se lee un día y una hora en todo texto para personas (mensajes del validador y de las Actions, bitácora,
 * avisos): «lunes», «7:00», sin cero a la izquierda, como la semana del editor y DESIGN.md. Vive en Core para que lo
 * usen todos los módulos del mapa; los datos (`related`, formularios, llaves) siguen en `HH:MM`.
 */
final class WeekTime
{
    private const array DAY_NAMES = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado'];

    /**
     * La hora de unos minutos desde la medianoche o de una hora guardada (`HH:MM` o `HH:MM:SS`): 420 o «07:00» → «7:00».
     */
    public static function clock(int|string $time): string
    {
        if (is_string($time)) {
            [$hours, $minutes] = array_map('intval', explode(':', $time));
            $time = $hours * 60 + $minutes;
        }

        return sprintf('%d:%02d', intdiv($time, 60), $time % 60);
    }

    /**
     * El nombre del día (1 = lunes a 6 = sábado), en minúsculas para ir dentro de una frase.
     */
    public static function day(int $day): string
    {
        return self::DAY_NAMES[$day] ?? (string) $day;
    }
}
