<?php

namespace App\Modules\Staffing\Rules;

use App\Modules\Scheduling\Validation\Minutes;

/**
 * El texto de una alternativa del motor elegida (CR-03) para los avisos: cómo queda el grupo pedido y qué otros grupos
 * se mueven, para que nadie la confunda con lo que se pidió.
 */
final class ChangeAlternativeText
{
    /**
     * «G queda lunes y martes de 8:00 a 9:00 en A-1, y miércoles de 10:00 a 11:00 en LAB-2; también se mueven H y J.»
     *
     * @param  array<string, mixed>  $item
     */
    public static function summary(array $item): string
    {
        $requested = collect($item['groups'] ?? [])->firstWhere('requested', true);
        $byTime = [];

        foreach ($requested['sessions'] ?? [] as $session) {
            $room = ($session['room'] ?? null) === null ? '' : " en {$session['room']}";
            $byTime[Minutes::labelOf($session['starts_at']).' a '.Minutes::labelOf($session['ends_at']).$room][] = Minutes::dayName((int) $session['day']);
        }

        $parts = array_map(fn (string $time, array $days): string => self::list($days)." de {$time}", array_keys($byTime), $byTime);
        $text = ($requested['code'] ?? 'El grupo').($parts === [] ? ' cambia de horario' : ' queda '.self::list($parts));
        $others = array_values(array_filter(array_column($item['affected_groups'] ?? [], 'code')));

        if ($others !== []) {
            $text .= count($others) === 1 ? "; también se mueve {$others[0]}" : '; también se mueven '.self::list($others);
        }

        return $text.'.';
    }

    /**
     * @param  list<string>  $items
     */
    private static function list(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items).' y '.$last;
    }
}
