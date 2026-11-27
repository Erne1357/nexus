<?php

namespace App\Modules\Scheduling\Rules;

use App\Modules\Scheduling\Validation\Minutes;

/**
 * La combinación del alumno tipo (HC-03): si se puede elegir un grupo de cada materia de un semestre sin que dos se
 * empalmen. Es pura y la comparten la regla TypicalStudentSchedule y el editor de horarios, que pinta dónde cabe un
 * grupo con la misma semántica.
 *
 * Entra por materia una lista de grupos, y cada grupo es una lista de franjas `['day' => 1, 'start' => '07:00',
 * 'end' => '09:00']` (las horas también pueden venir en minutos). Un grupo sin franjas cabe con todo. Busca con
 * backtracking, empezando por las materias con menos grupos: un semestre trae pocas materias con pocos grupos, así
 * que es inmediato.
 */
final class TypicalStudentCombination
{
    /**
     * @param  array<string, list<list<array{day: int, start: int|string, end: int|string}>>>  $options
     */
    public static function exists(array $options): bool
    {
        return self::find($options) !== null;
    }

    /**
     * Una combinación sin empalmes, como el índice del grupo elegido de cada materia en el orden en que entraron; null
     * si no hay.
     *
     * @param  array<string, list<list<array{day: int, start: int|string, end: int|string}>>>  $options
     * @return array<string, int>|null
     */
    public static function find(array $options): ?array
    {
        $normalized = self::normalize($options);
        $order = array_keys($normalized);
        usort($order, fn (string $a, string $b): int => count($normalized[$a]) <=> count($normalized[$b]));

        $chosen = self::search($normalized, $order, 0, [], []);

        if ($chosen === null) {
            return null;
        }

        $ordered = [];

        foreach (array_keys($options) as $subject) {
            $ordered[$subject] = $chosen[$subject];
        }

        return $ordered;
    }

    /**
     * Las materias de un conjunto mínimo sin combinación (quitar cualquiera ya deja una), en el orden en que entraron;
     * vacío si hay combinación. Es lo que se le muestra al usuario cuando ningún par de grupos únicos explica el choque.
     *
     * @param  array<string, list<list<array{day: int, start: int|string, end: int|string}>>>  $options
     * @return list<string>
     */
    public static function unsatisfiableCore(array $options): array
    {
        if (self::exists($options)) {
            return [];
        }

        $core = $options;

        foreach (array_keys($options) as $subject) {
            $without = $core;
            unset($without[$subject]);

            if (! self::exists($without)) {
                $core = $without;
            }
        }

        return array_keys($core);
    }

    /**
     * @param  array<string, list<list<array{int, int, int}>>>  $options
     * @param  list<string>  $order
     * @param  array<string, int>  $chosen
     * @param  list<array{int, int, int}>  $taken
     * @return array<string, int>|null
     */
    private static function search(array $options, array $order, int $position, array $chosen, array $taken): ?array
    {
        if ($position === count($order)) {
            return $chosen;
        }

        $subject = $order[$position];

        foreach ($options[$subject] as $index => $slots) {
            if (self::clashes($slots, $taken)) {
                continue;
            }

            $found = self::search($options, $order, $position + 1, [...$chosen, $subject => $index], [...$taken, ...$slots]);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  list<array{int, int, int}>  $slots
     * @param  list<array{int, int, int}>  $taken
     */
    private static function clashes(array $slots, array $taken): bool
    {
        foreach ($slots as [$day, $start, $end]) {
            foreach ($taken as [$takenDay, $takenStart, $takenEnd]) {
                if ($day === $takenDay && Minutes::overlap($start, $end, $takenStart, $takenEnd)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, list<list<array{day: int, start: int|string, end: int|string}>>>  $options
     * @return array<string, list<list<array{int, int, int}>>>
     */
    private static function normalize(array $options): array
    {
        $minutes = fn (int|string $time): int => is_int($time) ? $time : Minutes::of($time);

        return array_map(fn (array $groups): array => array_map(
            fn (array $slots): array => array_map(fn (array $slot): array => [(int) $slot['day'], $minutes($slot['start']), $minutes($slot['end'])], $slots),
            $groups,
        ), $options);
    }
}
