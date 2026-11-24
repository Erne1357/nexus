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

    /**
     * Viva: publicada, en curso o en la ronda extra. Mientras lo está, los docentes eligen sus grupos y el jefe o el JPD
     * asignan sin HC-16 y con HC-08 como aviso (diseño 1.33, 6.7).
     */
    public function isLive(): bool
    {
        return in_array($this, self::live(), true);
    }

    /**
     * @return list<self>
     */
    public static function live(): array
    {
        return [self::Published, self::Running, self::ExtraRound];
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
