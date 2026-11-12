<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Term;

/**
 * Si hay carga en curso en un periodo (MN-04, confirmado el 12 de noviembre de 2026): el periodo tiene cargas y al
 * menos una no está publicada. Una en re-análisis cuenta como no publicada (supuesto por confirmar). El menú la usa
 * para mostrar u ocultar las pantallas de la carga (MN-02); no decide quién puede abrirlas. Es de todo el periodo,
 * sin campus ni departamento.
 */
final class LoadProgressQuery
{
    public function inProgress(Term $term): bool
    {
        return AcademicLoad::query()
            ->where('term_id', $term->getKey())
            ->where('status', '!=', LoadStatus::Published)
            ->exists();
    }

    /**
     * El motivo por el que las pantallas de la carga no están disponibles en el periodo, o null si lo están. Sin
     * periodo elegido no lo están (supuesto por confirmar).
     */
    public function whyUnavailable(?Term $term): ?string
    {
        if ($term === null) {
            return 'No hay un periodo elegido';
        }

        return $this->inProgress($term) ? null : "No hay carga en curso en {$term->name}";
    }
}
