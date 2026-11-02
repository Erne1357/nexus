<?php

namespace App\Modules\CatalogValidation\Enums;

use App\Modules\Core\Enums\StatusTone;

/**
 * Estado de la revisión de un catálogo (docs/dominio/modelo-de-datos.md, `catalog_reviews`): nace
 * pendiente al abrir el periodo, pasa a en revisión cuando el jefe la empieza y termina validada. La carga
 * del departamento sale de borrador cuando sus revisiones están validadas (HC-14).
 */
enum ReviewStatus: string
{
    case Pending = 'pending';
    case InReview = 'in_review';
    case Validated = 'validated';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::InReview => 'En revisión',
            self::Validated => 'Validado',
        };
    }

    /**
     * Tono de la insignia de estado (DESIGN.md, "Insignias de estado"): pendiente en advertencia, en
     * revisión en info y validado en éxito.
     */
    public function tone(): StatusTone
    {
        return match ($this) {
            self::Pending => StatusTone::Warning,
            self::InReview => StatusTone::Info,
            self::Validated => StatusTone::Success,
        };
    }
}
