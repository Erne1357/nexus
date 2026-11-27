<?php

namespace App\Modules\Staffing\Rules;

use App\Modules\Faculty\Enums\PositionKind;

/**
 * La etiqueta corta de las plazas vigentes de un docente, para el ranking de la asignación: TC, 3/4, MT,
 * «Asig. N h», «Hon.» y «Apoyo N h». Varias plazas van en el orden de PositionKind, unidas con « + »; las de un
 * mismo tipo suman sus horas. Puro.
 */
final class PositionTag
{
    /**
     * @param  iterable<array{kind: PositionKind, base_hours: int}>  $positions
     */
    public static function of(iterable $positions): string
    {
        $hours = [];

        foreach ($positions as $position) {
            $hours[$position['kind']->value] = ($hours[$position['kind']->value] ?? 0) + (int) $position['base_hours'];
        }

        $tags = [];

        foreach (PositionKind::cases() as $kind) {
            if (array_key_exists($kind->value, $hours)) {
                $tags[] = self::tag($kind, $hours[$kind->value]);
            }
        }

        return $tags === [] ? 'Sin plaza' : implode(' + ', $tags);
    }

    private static function tag(PositionKind $kind, int $hours): string
    {
        return match ($kind) {
            PositionKind::TiempoCompleto => 'TC',
            PositionKind::TresCuartos => '3/4',
            PositionKind::MedioTiempo => 'MT',
            PositionKind::Asignatura => "Asig. {$hours} h",
            PositionKind::Honorarios => 'Hon.',
            PositionKind::Apoyo => "Apoyo {$hours} h",
        };
    }
}
