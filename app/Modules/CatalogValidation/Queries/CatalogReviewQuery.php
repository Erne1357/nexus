<?php

namespace App\Modules\CatalogValidation\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;

/**
 * Lecturas de las revisiones de catálogo.
 */
final class CatalogReviewQuery
{
    /**
     * Los catálogos que todavía bloquean la salida de borrador de la carga de un departamento en un campus
     * (HC-14, como la reescribe D2): cada catálogo general cuya revisión del periodo y el departamento (sin
     * campus) falta o no está validada, y los salones si el perfil del campus usa salones y la revisión del
     * periodo, el campus y el departamento falta o no está validada. En el orden del enum Catalog.
     *
     * Con `$lock` las revisiones que lee quedan con bloqueo compartido (FOR SHARE) hasta el fin de la
     * transacción: quien valida HC-14 dentro de una transacción (CatalogsValidated, desde TransitionLoad)
     * impide que otra sesión des-valide un catálogo entre la lectura y el cambio de estado. Fuera de una
     * transacción no tiene efecto.
     *
     * @return list<Catalog>
     */
    public function pendingFor(Term|string $term, Campus $campus, Department|string $department, bool $lock = false): array
    {
        $termId = $term instanceof Term ? $term->getKey() : $term;
        $departmentId = $department instanceof Department ? $department->getKey() : $department;

        $required = [...Catalog::general(), ...($campus->profile->usesRooms ? [Catalog::Rooms] : [])];

        $validated = CatalogReview::query()
            ->where('term_id', $termId)
            ->where('department_id', $departmentId)
            ->where('status', ReviewStatus::Validated)
            ->when($lock, fn ($query) => $query->sharedLock())
            ->where(fn ($query) => $query->whereNull('campus_id')->orWhere('campus_id', $campus->getKey()))
            ->get()
            ->filter(fn (CatalogReview $review): bool => $review->catalog->isGeneral() === ($review->campus_id === null))
            ->pluck('catalog')
            ->all();

        $pending = array_filter($required, fn (Catalog $catalog): bool => ! in_array($catalog, $validated, true));

        usort($pending, fn (Catalog $a, Catalog $b): int => array_search($a, Catalog::cases(), true) <=> array_search($b, Catalog::cases(), true));

        return array_values($pending);
    }
}
