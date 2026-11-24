<?php

namespace App\Modules\Staffing\Enums;

/**
 * Estado del renglón de un docente en la cola de selección: esperando su turno, cerrado, pendiente de la ronda extra o
 * sin resolver al terminar.
 */
enum SelectionEntryStatus: string
{
    case Waiting = 'waiting';
    case Done = 'done';
    case PendingExtra = 'pending_extra';
    case Unresolved = 'unresolved';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Esperando',
            self::Done => 'Terminó',
            self::PendingExtra => 'Pendiente de la ronda extra',
            self::Unresolved => 'Sin resolver',
        };
    }
}
