<?php

namespace App\Modules\Core\Enums;

/**
 * Estado de una etapa del calendario del periodo respecto a hoy (America/Ciudad_Juarez). Solo
 * informa: ninguna regla rechaza nada por fecha (D4, confirmado el 1 de noviembre de 2026).
 */
enum StageStatus: string
{
    case Unscheduled = 'unscheduled';
    case Upcoming = 'upcoming';
    case InProgress = 'in_progress';
    case Finished = 'finished';

    public function label(): string
    {
        return match ($this) {
            self::Unscheduled => 'Sin fecha',
            self::Upcoming => 'Próxima',
            self::InProgress => 'En curso',
            self::Finished => 'Terminada',
        };
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Unscheduled => StatusTone::Draft,
            self::Upcoming => StatusTone::Info,
            self::InProgress => StatusTone::Success,
            self::Finished => StatusTone::Neutral,
        };
    }
}
