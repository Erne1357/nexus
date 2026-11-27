<?php

namespace App\Modules\Staffing\Enums;

use App\Modules\Core\Enums\StatusTone;

/**
 * Las marcas de un docente en el ranking de un grupo (reglas-de-negocio.md, «Ranking docente (desempate)»), como las
 * guarda el desglose (`staffing_rankings.breakdown.flags`): choque con su horario (HC-01) y no disponible (HC-09) lo
 * bajan al bloque final (lowers()); pasa su banda de horas frente a grupo (HC-08, clave `sin_horas`) es aviso desde la
 * tarea 1.50 y no lo baja. El nombre, el tono y el icono son los de la asignación, para que la oferta del docente las
 * pinte igual.
 */
enum RankingMark: string
{
    case Clash = 'choque';
    case NoHours = 'sin_horas';
    case Unavailable = 'no_disponible';

    public function label(): string
    {
        return match ($this) {
            self::Clash => 'Choque',
            self::NoHours => 'Pasa su banda',
            self::Unavailable => 'No disponible',
        };
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Clash => StatusTone::Error,
            self::NoHours => StatusTone::Warning,
            self::Unavailable => StatusTone::Draft,
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Clash => 'o-x-circle',
            self::NoHours => 'o-clock',
            self::Unavailable => 'o-no-symbol',
        };
    }

    /**
     * Si baja al docente al bloque final: choque y no disponible sí; pasar su banda (HC-08) es aviso.
     */
    public function lowers(): bool
    {
        return $this !== self::NoHours;
    }

    /**
     * Las marcas de las banderas de un desglose, en el orden del enum; las demás banderas (`sin_evaluacion`) no cuentan.
     *
     * @param  list<string>  $flags
     * @return list<self>
     */
    public static function fromFlags(array $flags): array
    {
        return array_values(array_filter(self::cases(), fn (self $mark): bool => in_array($mark->value, $flags, true)));
    }
}
