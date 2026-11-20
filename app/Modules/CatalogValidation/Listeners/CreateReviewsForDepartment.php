<?php

namespace App\Modules\CatalogValidation\Listeners;

use App\Modules\Academic\Events\DepartmentCreated;
use App\Modules\Academic\Events\DepartmentUpdated;
use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\CatalogOwner;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;

/**
 * Un departamento que se da de alta o se reactiva recibe sus revisiones de los catálogos que el admin ya abrió (CV9,
 * CV-S12; supuesto por confirmar) en cada periodo abierto en algún campus que todavía no termina: solo docentes y
 * salones, porque materias, planes y carreras son una revisión de Servicios Escolares para todo el Tec (CV15). Sin esto, la carga
 * del departamento quedaría bloqueada por una revisión que nadie puede crear (HC-14). Idempotente: no toca lo que ya existe.
 */
final class CreateReviewsForDepartment
{
    public function __construct(
        private readonly CatalogReviewQuery $reviews,
        private readonly CreateTermReviews $createTermReviews,
    ) {}

    public function handle(DepartmentCreated|DepartmentUpdated $event): void
    {
        if ($event instanceof DepartmentUpdated && ! in_array('is_active', $event->changed, true)) {
            return;
        }

        if (! $event->department->is_active) {
            return;
        }

        $terms = Term::query()
            ->whereIn('id', TermOpening::query()->select('term_id'))
            ->whereDate('ends_on', '>=', today())
            ->get();

        foreach ($terms as $term) {
            $opened = array_values(array_filter($this->reviews->openedCatalogs($term), fn (Catalog $catalog): bool => $catalog->owner() === CatalogOwner::Department));

            if ($opened !== []) {
                $this->createTermReviews->handle($term, $opened);
            }
        }
    }
}
