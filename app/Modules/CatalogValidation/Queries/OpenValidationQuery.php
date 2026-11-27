<?php

namespace App\Modules\CatalogValidation\Queries;

use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Navigation\TermAvailability;
use Closure;

/**
 * Si la validación de catálogos está abierta en un periodo (MN-03, confirmado el 12 de noviembre de 2026): lo
 * está mientras tenga al menos una revisión pendiente o en revisión. El menú la usa para mostrar u ocultar
 * Validar catálogos y Avance de validación (MN-02); no decide quién puede abrir las pantallas.
 */
final class OpenValidationQuery
{
    /** @var (Closure(Actor): ?string)|null */
    private static ?Closure $menuCondition = null;

    public function existsIn(Term $term): bool
    {
        return CatalogReview::query()
            ->where('term_id', $term->getKey())
            ->whereIn('status', [ReviewStatus::Pending, ReviewStatus::InReview])
            ->exists();
    }

    /**
     * La condición del menú para las entradas de la validación (MN-02):
     * una sola closure para todas, así Navigation la evalúa una vez por menú. Sin periodo elegido responde Core (TermAvailability). Se guarda la primera que se arma: no lleva nada de
     * la petición, así que se puede compartir entre peticiones (Octane).
     *
     * @return Closure(Actor): ?string
     */
    public static function menuCondition(): Closure
    {
        return self::$menuCondition ??= TermAvailability::of(static fn (Term $term): ?string => app(self::class)->whyUnavailable($term));
    }

    /**
     * El motivo por el que esas pantallas no están disponibles en el periodo, o null si lo están.
     */
    public function whyUnavailable(Term $term): ?string
    {
        return $this->existsIn($term) ? null : "No hay validación abierta en {$term->name}";
    }
}
