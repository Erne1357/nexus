<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Navigation\TermAvailability;
use Closure;

/**
 * Si hay carga en curso en un periodo (MN-04, confirmado el 12 de noviembre de 2026): el periodo tiene cargas y al
 * menos una no está publicada. Una en re-análisis cuenta como no publicada (supuesto por confirmar). El menú la usa
 * para mostrar u ocultar las pantallas de la carga (MN-02); no decide quién puede abrirlas. Es de todo el periodo,
 * sin campus ni departamento.
 */
final class LoadProgressQuery
{
    /** @var (Closure(Actor): ?string)|null */
    private static ?Closure $menuCondition = null;

    public function inProgress(Term $term): bool
    {
        return AcademicLoad::query()
            ->where('term_id', $term->getKey())
            ->where('status', '!=', LoadStatus::Published)
            ->exists();
    }

    /**
     * La condición del menú para las entradas de la carga, que declaran AcademicLoad, Scheduling y Staffing (MN-02):
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
        return $this->inProgress($term) ? null : "No hay carga en curso en {$term->name}";
    }
}
