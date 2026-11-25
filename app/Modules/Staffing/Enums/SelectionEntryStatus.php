<?php

namespace App\Modules\Staffing\Enums;

/**
 * Estado del renglón de un docente en la cola de selección: esperando su turno, cerrado, pendiente de la ronda extra o
 * sin resolver al terminar. `prior_choice` (SEL-16) es el de quien ya trae sus grupos: no tiene turno ni lugar.
 */
enum SelectionEntryStatus: string
{
    case Waiting = 'waiting';
    case Done = 'done';
    case PendingExtra = 'pending_extra';
    case Unresolved = 'unresolved';
    case PriorChoice = 'prior_choice';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Esperando',
            self::Done => 'Terminó',
            self::PendingExtra => 'Pendiente de la ronda extra',
            self::Unresolved => 'Sin resolver',
            self::PriorChoice => 'Elección previa',
        };
    }
}
