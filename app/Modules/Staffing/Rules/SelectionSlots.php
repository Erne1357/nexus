<?php

namespace App\Modules\Staffing\Rules;

use Carbon\CarbonImmutable;

/**
 * SEL-08. Turnos fijos de la selección: consecutivos dentro de una ventana diaria y en los días de la semana dados
 * (ISO, 1 = lunes). Un turno que no cabe completo pasa al siguiente día válido a la hora de inicio de la ventana.
 * La segunda ronda arranca en el primer inicio de turno que sea ≥ fin de la primera + 30 minutos dentro de la
 * ventana, y si no, el siguiente día válido a la hora de inicio. Los turnos se alinean a la cuadrícula de la ventana
 * (inicio de la ventana + k turnos): `extraRoundStart()` siempre cae en ella (redondea hacia arriba) y se espera que
 * el `startsAt` de `layout()` también. Puro; zona America/Ciudad_Juarez.
 */
final readonly class SelectionSlots
{
    public const TIMEZONE = 'America/Ciudad_Juarez';

    public const EXTRA_ROUND_GAP_MINUTES = 30;

    /**
     * @param  list<int>  $weekdays
     * @return list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>
     */
    public static function layout(CarbonImmutable $startsAt, string $windowStarts, string $windowEnds, array $weekdays, int $slotMinutes, int $count): array
    {
        $slots = [];
        $cursor = $startsAt->setTimezone(self::TIMEZONE);

        for ($i = 0; $i < $count; $i++) {
            $cursor = self::fit($cursor, $windowStarts, $windowEnds, $weekdays, $slotMinutes);
            $end = $cursor->addMinutes($slotMinutes);
            $slots[] = ['starts_at' => $cursor, 'ends_at' => $end];
            $cursor = $end;
        }

        return $slots;
    }

    /**
     * @param  list<int>  $weekdays
     */
    public static function extraRoundStart(CarbonImmutable $firstRoundEnd, string $windowStarts, string $windowEnds, array $weekdays, int $slotMinutes): CarbonImmutable
    {
        $earliest = $firstRoundEnd->setTimezone(self::TIMEZONE)->addMinutes(self::EXTRA_ROUND_GAP_MINUTES);
        $earliest = $earliest->second > 0 || $earliest->microsecond > 0 ? $earliest->startOfMinute()->addMinute() : $earliest;
        $candidate = self::fit($earliest, $windowStarts, $windowEnds, $weekdays, $slotMinutes);

        // Alinea el inicio a la cuadrícula de turnos del día (inicio de la ventana + k turnos).
        [$startHour, $startMinute] = array_map('intval', explode(':', $windowStarts));
        $open = $candidate->setTime($startHour, $startMinute);
        $offset = (int) round($open->diffInMinutes($candidate, true));
        $aligned = $open->addMinutes((int) (ceil($offset / $slotMinutes) * $slotMinutes));

        return self::fit($aligned, $windowStarts, $windowEnds, $weekdays, $slotMinutes);
    }

    /**
     * El primer inicio válido en o después de $moment: día permitido, dentro de la ventana y con espacio para un turno.
     *
     * @param  list<int>  $weekdays
     */
    private static function fit(CarbonImmutable $moment, string $windowStarts, string $windowEnds, array $weekdays, int $slotMinutes): CarbonImmutable
    {
        [$startHour, $startMinute] = array_map('intval', explode(':', $windowStarts));
        [$endHour, $endMinute] = array_map('intval', explode(':', $windowEnds));

        for ($guard = 0; $guard < 400; $guard++) {
            if (in_array($moment->dayOfWeekIso, $weekdays, true)) {
                $open = $moment->setTime($startHour, $startMinute);
                $close = $moment->setTime($endHour, $endMinute);
                $candidate = $moment < $open ? $open : $moment;

                if ($candidate->addMinutes($slotMinutes) <= $close) {
                    return $candidate;
                }
            }

            $moment = $moment->addDay()->setTime($startHour, $startMinute);
        }

        throw new \InvalidArgumentException('La ventana de turnos no deja ningún turno válido.');
    }
}
