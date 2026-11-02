<?php

namespace App\Modules\AcademicLoad\Enums;

use App\Modules\Core\Enums\StatusTone;

/**
 * Estado de lo que una carrera le pide a un departamento, en la bandeja del departamento (reglas-de-negocio.md,
 * "Propuesta de carrera"). No se guarda: sin parte del departamento (sin propuesta, en borrador o sin nada que
 * pedirle) es Sin enviar; con parte, el de la parte. Los casos van en el orden de la bandeja, pendientes primero
 * (supuesto por confirmar).
 */
enum InboxStatus: string
{
    case Pending = 'pending';
    case Returned = 'returned';
    case Accepted = 'accepted';
    case NotSubmitted = 'not_submitted';

    public static function ofPart(?ProposalReviewStatus $part): self
    {
        return match ($part) {
            null => self::NotSubmitted,
            ProposalReviewStatus::Pending => self::Pending,
            ProposalReviewStatus::Accepted => self::Accepted,
            ProposalReviewStatus::Returned => self::Returned,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Returned => 'Regresada',
            self::Accepted => 'Aceptada',
            self::NotSubmitted => 'Sin enviar',
        };
    }

    /**
     * Tono de la insignia de estado (DESIGN.md, "Insignias de estado"): los de la parte (ProposalReviewStatus), y
     * sin enviar en el tono del borrador.
     */
    public function tone(): StatusTone
    {
        return match ($this) {
            self::Pending => StatusTone::Warning,
            self::Returned => StatusTone::Error,
            self::Accepted => StatusTone::Success,
            self::NotSubmitted => StatusTone::Draft,
        };
    }

    /**
     * Su lugar en la bandeja: el de su caso.
     */
    public function order(): int
    {
        return (int) array_search($this, self::cases(), true);
    }
}
