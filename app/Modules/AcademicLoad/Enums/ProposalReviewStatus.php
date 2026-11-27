<?php

namespace App\Modules\AcademicLoad\Enums;

use App\Modules\Core\Enums\StatusTone;

/**
 * Estado de la parte de un departamento en una propuesta de carrera (docs/dominio/modelo-de-datos.md,
 * `proposal_reviews`): pendiente al enviarse, y el departamento la acepta o la regresa con comentario
 * (confirmado el 2 de noviembre de 2026).
 */
enum ProposalReviewStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Accepted => 'Aceptada',
            self::Returned => 'Regresada',
        };
    }

    /**
     * Tono de la insignia de estado (DESIGN.md, "Insignias de estado"): pendiente en advertencia, aceptada en
     * éxito y regresada en error, porque el departamento no la tomó.
     */
    public function tone(): StatusTone
    {
        return match ($this) {
            self::Pending => StatusTone::Warning,
            self::Accepted => StatusTone::Success,
            self::Returned => StatusTone::Error,
        };
    }
}
