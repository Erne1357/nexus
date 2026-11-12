<?php

namespace App\Modules\CatalogValidation\Queries;

use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Models\Term;

/**
 * Si la validación de catálogos está abierta en un periodo (MN-03, confirmado el 12 de noviembre de 2026): lo
 * está mientras tenga al menos una revisión pendiente o en revisión. El menú la usa para mostrar u ocultar
 * Validar catálogos y Avance de validación (MN-02); no decide quién puede abrir las pantallas.
 */
final class OpenValidationQuery
{
    public function existsIn(Term $term): bool
    {
        return CatalogReview::query()
            ->where('term_id', $term->getKey())
            ->whereIn('status', [ReviewStatus::Pending, ReviewStatus::InReview])
            ->exists();
    }

    /**
     * El motivo por el que las pantallas de la validación no están disponibles en el periodo, o null si lo
     * están. Sin periodo elegido no lo están (supuesto por confirmar).
     */
    public function whyUnavailable(?Term $term): ?string
    {
        if ($term === null) {
            return 'No hay un periodo elegido';
        }

        return $this->existsIn($term) ? null : "No hay validación abierta en {$term->name}";
    }
}
