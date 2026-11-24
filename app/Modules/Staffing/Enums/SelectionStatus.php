<?php

namespace App\Modules\Staffing\Enums;

use App\Modules\Core\Enums\StatusTone;

/**
 * Ciclo de una selección de carga (diseño 1.33, 6.1): borrador, publicada, en curso, ronda extra y terminada; se
 * cancela solo antes de que empiece.
 */
enum SelectionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Running = 'running';
    case ExtraRound = 'extra_round';
    case Finished = 'finished';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Published => 'Publicada',
            self::Running => 'En curso',
            self::ExtraRound => 'Ronda extra',
            self::Finished => 'Terminada',
            self::Cancelled => 'Cancelada',
        };
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Draft => StatusTone::Draft,
            self::Published, self::Running, self::ExtraRound => StatusTone::Info,
            self::Finished => StatusTone::Success,
            self::Cancelled => StatusTone::Error,
        };
    }
}
