<?php

namespace App\Modules\CatalogValidation\Listeners;

use App\Modules\Academic\Events\DepartmentCreated;
use App\Modules\Academic\Events\DepartmentUpdated;
use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;

/**
 * Un departamento que se da de alta o se reactiva recibe sus revisiones de los catálogos que el admin ya abrió (CV9,
 * CV-S12; supuesto por confirmar) en cada periodo abierto en algún campus que todavía no termina. Sin esto, la carga
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
            $opened = $this->reviews->openedCatalogs($term);

            if ($opened !== []) {
                $this->createTermReviews->handle($term, $opened);
            }
        }
    }
}
