<?php

namespace App\Modules\AcademicLoad\Enums;

use App\Modules\Core\Enums\StatusTone;

/**
 * Estado de un acuerdo entre departamentos: nace propuesto; el departamento que decide lo acepta o lo rechaza con
 * motivo, y quien lo pidió lo cancela si ya no lo necesita (su sesión cambió de salón, de día o de horas, o quitó el
 * cupo) (supuesto por confirmar). Solo cuentan los vivos: propuestos y aceptados.
 */
enum AgreementStatus: string
{
    case Proposed = 'proposed';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Proposed => 'Pendiente',
            self::Accepted => 'Aceptado',
            self::Rejected => 'Rechazado',
            self::Cancelled => 'Cancelado',
        };
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Proposed => StatusTone::Warning,
            self::Accepted => StatusTone::Success,
            self::Rejected => StatusTone::Error,
            self::Cancelled => StatusTone::Neutral,
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Proposed => 'o-clock',
            self::Accepted => 'o-check-circle',
            self::Rejected => 'o-x-circle',
            self::Cancelled => 'o-minus-circle',
        };
    }

    /** Propuesto o aceptado: el que aparta el salón o el cupo. */
    public function isLive(): bool
    {
        return $this === self::Proposed || $this === self::Accepted;
    }

    /**
     * @return list<string>
     */
    public static function liveValues(): array
    {
        return [self::Proposed->value, self::Accepted->value];
    }
}
