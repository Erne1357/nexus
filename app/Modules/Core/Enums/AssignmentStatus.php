<?php

namespace App\Modules\Core\Enums;

use App\Modules\Core\Models\RoleAssignment;
use Carbon\CarbonImmutable;

/**
 * Cómo está un nombramiento un día dado: vigente, programado (empieza después) o terminado (su último día quedó
 * atrás). Los dos extremos, `valid_from` y `valid_to`, cuentan completos.
 */
enum AssignmentStatus: string
{
    case Current = 'vigente';
    case Scheduled = 'programado';
    case Ended = 'terminado';

    public function label(): string
    {
        return match ($this) {
            self::Current => 'Vigente',
            self::Scheduled => 'Programado',
            self::Ended => 'Terminado',
        };
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Current => StatusTone::Success,
            self::Scheduled => StatusTone::Info,
            self::Ended => StatusTone::Neutral,
        };
    }

    public static function of(RoleAssignment $assignment, CarbonImmutable $today): self
    {
        $day = $today->toDateString();

        return match (true) {
            $assignment->valid_from->toDateString() > $day => self::Scheduled,
            $assignment->valid_to !== null && $assignment->valid_to->toDateString() < $day => self::Ended,
            default => self::Current,
        };
    }
}
