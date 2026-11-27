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

    /**
     * El tono de la insignia: el mismo color que la barra y la leyenda de la línea de tiempo (azul en curso, gris
     * próxima, verde terminada, punteada sin fecha).
     */
    public function tone(): StatusTone
    {
        return match ($this) {
            self::Unscheduled => StatusTone::Draft,
            self::Upcoming => StatusTone::Neutral,
            self::InProgress => StatusTone::Info,
            self::Finished => StatusTone::Success,
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Unscheduled => 'o-minus-circle',
            self::Upcoming => 'o-calendar',
            self::InProgress => 'o-clock',
            self::Finished => 'o-check-circle',
        };
    }

    /**
     * Las clases de la barra de la etapa en la línea de tiempo; la terminada va atenuada para que un periodo
     * pasado no sea lo más fuerte de la pantalla.
     */
    public function barClasses(): string
    {
        return match ($this) {
            self::Unscheduled => '',
            self::Upcoming => 'border border-base-content/50 bg-base-content/15',
            self::InProgress => 'bg-info',
            self::Finished => 'border border-success/60 bg-success/35',
        };
    }
}
