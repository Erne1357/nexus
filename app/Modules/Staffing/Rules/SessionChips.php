<?php

namespace App\Modules\Staffing\Rules;

/**
 * Las sesiones de un grupo como fichas para la lista «Carga actual» (SEL-18): las que comparten horas y salón se juntan
 * en una ficha con sus días («Lu–Vi 10–11 · 703»); tres o más días seguidos van con guion y los demás con diagonal.
 * Una sesión sin salón lleva `room` null y `virtual` false si es presencial. Puro.
 */
final class SessionChips
{
    private const array DAYS = [1 => 'Lu', 2 => 'Ma', 3 => 'Mi', 4 => 'Ju', 5 => 'Vi', 6 => 'Sa'];

    /**
     * @param  iterable<array{day: int, starts_at: string, ends_at: string, room: ?string, virtual: bool}>  $sessions
     * @return list<array{label: string, room: ?string, virtual: bool}>
     */
    public static function of(iterable $sessions): array
    {
        $blocks = [];

        foreach ($sessions as $session) {
            $starts = substr((string) $session['starts_at'], 0, 5);
            $ends = substr((string) $session['ends_at'], 0, 5);
            $key = "{$starts}|{$ends}|".($session['room'] ?? '').'|'.($session['virtual'] ? 'v' : 'p');
            $blocks[$key] ??= ['days' => [], 'starts' => $starts, 'ends' => $ends, 'room' => $session['room'], 'virtual' => (bool) $session['virtual']];
            $blocks[$key]['days'][] = (int) $session['day'];
        }

        $chips = array_map(function (array $block): array {
            $days = array_values(array_unique($block['days']));
            sort($days);

            return [
                'first' => $days[0],
                'starts' => $block['starts'],
                'label' => self::days($days).' '.self::time($block['starts']).'–'.self::time($block['ends']),
                'room' => $block['room'],
                'virtual' => $block['virtual'],
            ];
        }, array_values($blocks));

        usort($chips, fn (array $a, array $b): int => [$a['first'], $a['starts']] <=> [$b['first'], $b['starts']]);

        return array_map(fn (array $chip): array => ['label' => $chip['label'], 'room' => $chip['room'], 'virtual' => $chip['virtual']], $chips);
    }

    /**
     * @param  list<int>  $days
     */
    private static function days(array $days): string
    {
        $runs = [];

        foreach ($days as $day) {
            $last = array_key_last($runs);

            if ($last !== null && end($runs[$last]) === $day - 1) {
                $runs[$last][] = $day;
            } else {
                $runs[] = [$day];
            }
        }

        return implode('/', array_map(function (array $run): string {
            $name = fn (int $day): string => self::DAYS[$day] ?? (string) $day;

            return count($run) >= 3 ? $name($run[0]).'–'.$name($run[count($run) - 1]) : implode('/', array_map($name, $run));
        }, $runs));
    }

    private static function time(string $value): string
    {
        [$hours, $minutes] = array_map('intval', explode(':', $value) + [1 => 0]);

        return $minutes === 0 ? (string) $hours : sprintf('%d:%02d', $hours, $minutes);
    }
}
