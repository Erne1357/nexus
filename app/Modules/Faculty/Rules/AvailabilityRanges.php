<?php

namespace App\Modules\Faculty\Rules;

use App\Modules\Faculty\Enums\AvailabilityKind;
use InvalidArgumentException;

/**
 * Unión de bloques de disponibilidad (`teacher_availability`): lo que se marca en la cuadrícula día × hora son
 * bloques del perfil del campus; lo que se guarda son rangos. Los bloques contiguos del mismo tipo y del mismo día
 * forman un rango; un cambio de tipo, de día o un hueco empieza otro. El orden de entrada no importa: el resultado
 * sale por día y hora. Pura: no lee la base ni el perfil, solo la duración del bloque. Las horas van en `HH:MM`.
 */
final class AvailabilityRanges
{
    /** Nombre de cada día de `teacher_availability.day` (ISO-8601, 1 = lunes, como en el perfil del campus). */
    public const array DAY_NAMES = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado'];

    /**
     * Los rangos de unas celdas de `$blockMinutes` minutos cada una.
     *
     * @param  list<array{day: int, starts_at: string, kind: AvailabilityKind}>  $cells
     * @return list<array{day: int, starts_at: string, ends_at: string, kind: AvailabilityKind}>
     *
     * @throws InvalidArgumentException si una celda viene dos veces o se pasa de la medianoche.
     */
    public static function merge(array $cells, int $blockMinutes): array
    {
        $ranges = [];
        $seen = [];

        foreach ($cells as $cell) {
            $key = $cell['day'].'|'.$cell['starts_at'];

            if (isset($seen[$key])) {
                throw new InvalidArgumentException("La celda {$cell['starts_at']} del día {$cell['day']} viene dos veces.");
            }

            $seen[$key] = true;
            $start = self::minutes($cell['starts_at']);
            $ranges[] = ['day' => $cell['day'], 'starts_at' => $cell['starts_at'], 'ends_at' => self::clock($start + $blockMinutes), 'kind' => $cell['kind']];
        }

        return self::join($ranges);
    }

    /**
     * Une rangos contiguos del mismo tipo y día, midan lo que midan, y los ordena por día y hora. No revisa traslapes:
     * los rangos que recibe no se traslapan.
     *
     * @param  list<array{day: int, starts_at: string, ends_at: string, kind: AvailabilityKind}>  $ranges
     * @return list<array{day: int, starts_at: string, ends_at: string, kind: AvailabilityKind}>
     */
    public static function join(array $ranges): array
    {
        usort($ranges, fn (array $a, array $b): int => [$a['day'], self::minutes($a['starts_at'])] <=> [$b['day'], self::minutes($b['starts_at'])]);

        $joined = [];

        foreach ($ranges as $range) {
            $last = array_key_last($joined);

            if ($last !== null
                && $joined[$last]['day'] === $range['day']
                && $joined[$last]['kind'] === $range['kind']
                && $joined[$last]['ends_at'] === $range['starts_at']) {
                $joined[$last]['ends_at'] = $range['ends_at'];

                continue;
            }

            $joined[] = ['day' => $range['day'], 'starts_at' => $range['starts_at'], 'ends_at' => $range['ends_at'], 'kind' => $range['kind']];
        }

        return $joined;
    }

    /**
     * Los bloques de una jornada: de `$dayStart` a `$dayEnd`, de `$blockMinutes` cada uno.
     *
     * @return list<array{starts_at: string, ends_at: string}>
     */
    public static function blocks(string $dayStart, string $dayEnd, int $blockMinutes): array
    {
        $blocks = [];

        for ($minutes = self::minutes($dayStart); $minutes + $blockMinutes <= self::minutes($dayEnd); $minutes += $blockMinutes) {
            $blocks[] = ['starts_at' => self::clock($minutes), 'ends_at' => self::clock($minutes + $blockMinutes)];
        }

        return $blocks;
    }

    /**
     * El tipo con que se ve un bloque de un día: no disponible si algún rango no disponible lo toca, aunque sea en
     * parte; si no, preferido si alguno preferido lo toca; null es disponible.
     *
     * @param  iterable<array{starts_at: string, ends_at: string, kind: AvailabilityKind}>  $ranges  Los rangos de ese día.
     */
    public static function kindOver(iterable $ranges, string $startsAt, string $endsAt): ?AvailabilityKind
    {
        $kind = null;

        foreach ($ranges as $range) {
            if (self::minutes($range['starts_at']) < self::minutes($endsAt) && self::minutes($range['ends_at']) > self::minutes($startsAt)) {
                if ($range['kind'] === AvailabilityKind::Unavailable) {
                    return AvailabilityKind::Unavailable;
                }

                $kind = $range['kind'];
            }
        }

        return $kind;
    }

    /**
     * Los rangos sin el tramo de `$startsAt` a `$endsAt` del día `$day`: el que lo cruza se recorta o se parte en dos;
     * los de otros días no cambian.
     *
     * @param  list<array{day: int, starts_at: string, ends_at: string, kind: AvailabilityKind}>  $ranges
     * @return list<array{day: int, starts_at: string, ends_at: string, kind: AvailabilityKind}>
     */
    public static function cut(array $ranges, int $day, string $startsAt, string $endsAt): array
    {
        $from = self::minutes($startsAt);
        $to = self::minutes($endsAt);
        $left = [];

        foreach ($ranges as $range) {
            $start = self::minutes($range['starts_at']);
            $end = self::minutes($range['ends_at']);

            if ($range['day'] !== $day || $end <= $from || $start >= $to) {
                $left[] = $range;

                continue;
            }

            if ($start < $from) {
                $left[] = [...$range, 'ends_at' => $startsAt];
            }

            if ($end > $to) {
                $left[] = [...$range, 'starts_at' => $endsAt];
            }
        }

        return $left;
    }

    /**
     * Minutos desde la medianoche de `HH:MM` (o `HH:MM:SS`, como la da `time`).
     */
    public static function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }

    /**
     * `HH:MM` de unos minutos desde la medianoche, sin pasar de ella.
     *
     * @throws InvalidArgumentException
     */
    public static function clock(int $minutes): string
    {
        if ($minutes < 0 || $minutes >= 24 * 60) {
            throw new InvalidArgumentException('Un bloque de disponibilidad no pasa de la medianoche.');
        }

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
