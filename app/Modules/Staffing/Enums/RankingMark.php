<?php

namespace App\Modules\Staffing\Enums;

use App\Modules\Core\Enums\StatusTone;

/**
 * Las marcas que bajan a un docente en el ranking de un grupo (reglas-de-negocio.md, «Ranking docente (desempate)»),
 * como las guarda el desglose (`staffing_rankings.breakdown.flags`): choque con su horario (HC-01) y no disponible
 * (HC-09) y, con plaza administrativa, que no le caben las horas del grupo en su tope de 42 h (HC-22, `sin_cupo`), al bloque final; pasa su banda de horas frente a grupo (HC-08, clave `sin_horas`; desde la tarea 1.50 se
 * puede asignar), a su bloque «Pasan su banda». El nombre, el tono y el icono son los de la asignación, para que la
 * oferta del docente las pinte igual.
 */
enum RankingMark: string
{
    case Clash = 'choque';
    case NoHours = 'sin_horas';
    case Unavailable = 'no_disponible';
    case NoRoom = 'sin_cupo';

    public function label(): string
    {
        return match ($this) {
            self::Clash => 'Choque',
            self::NoHours => 'Con horas extra',
            self::Unavailable => 'No disponible',
            self::NoRoom => 'No le caben',
        };
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Clash => StatusTone::Error,
            self::NoHours => StatusTone::Warning,
            self::Unavailable => StatusTone::Draft,
            self::NoRoom => StatusTone::Draft,
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Clash => 'o-x-circle',
            self::NoHours => 'o-clock',
            self::Unavailable => 'o-no-symbol',
            self::NoRoom => 'o-no-symbol',
        };
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
