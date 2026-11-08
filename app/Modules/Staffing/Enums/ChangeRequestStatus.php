<?php

namespace App\Modules\Staffing\Enums;

use App\Modules\Core\Enums\StatusTone;

/**
 * Estado de una solicitud de cambio. Abierta es pendiente, o aprobada sin aplicar.
 */
enum ChangeRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Applied = 'applied';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Approved => 'Aprobada',
            self::Rejected => 'Rechazada',
            self::Applied => 'Aplicada',
        };
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Pending => StatusTone::Warning,
            self::Approved => StatusTone::Info,
            self::Rejected => StatusTone::Error,
            self::Applied => StatusTone::Success,
        };
    }

    /**
     * El icono de su insignia: aprobada lleva `o-check` y no el `o-eye` del tono info, que en DESIGN.md es «En revisión».
     */
    public function icon(): string
    {
        return match ($this) {
            self::Pending => 'o-clock',
            self::Approved => 'o-check',
            self::Rejected => 'o-x-circle',
            self::Applied => 'o-check-circle',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Approved;
    }
}
