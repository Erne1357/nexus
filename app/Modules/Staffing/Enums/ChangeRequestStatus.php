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

    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Approved;
    }
}
