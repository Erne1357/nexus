<?php

namespace App\Modules\Faculty\Rules;

use Carbon\CarbonInterface;

/**
 * Horas base de un docente en una fecha: la suma de las plazas vigentes ese día, sin tope en la suma
 * (S2, supuesto por confirmar; cada plaza ya trae su máximo de 40). Un límite nulo es sin límite y el
 * último día de vigencia cuenta. El día se cuenta en Ciudad Juárez aunque la fecha llegue en otra zona.
 */
final class BaseHours
{
    /** Zona del día de vigencia; la regla es pura y no lee la configuración. */
    private const string TIMEZONE = 'America/Ciudad_Juarez';

    /**
     * @param  iterable<array{base_hours: int, valid_from: ?string, valid_to: ?string}>  $positions
     */
    public static function onDate(iterable $positions, CarbonInterface $date): int
    {
        $day = $date->toImmutable()->setTimezone(self::TIMEZONE)->toDateString();
        $total = 0;

        foreach ($positions as $position) {
            $from = $position['valid_from'];
            $to = $position['valid_to'];

            if (($from === null || substr($from, 0, 10) <= $day) && ($to === null || substr($to, 0, 10) >= $day)) {
                $total += $position['base_hours'];
            }
        }

        return $total;
    }
}
