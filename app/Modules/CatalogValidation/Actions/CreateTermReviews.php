<?php

namespace App\Modules\CatalogValidation\Actions;

use App\Modules\Academic\Queries\DepartmentQuery;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Models\Term;

/**
 * Crea las revisiones de los catálogos dados que falten en un periodo: por cada departamento activo y sin borrar, una
 * por catálogo, todas sin campus (la de salones es una para todos los campus, decisión 6, confirmada el 12 de
 * noviembre de 2026). Nacen pendientes. Devuelve cuántas creó: 0 si no faltaba ninguna.
 *
 * Es la pieza de escritura de OpenCatalogValidation (CV9), que decide qué catálogos y deja la bitácora, y de
 * CreateRoomsReviewsOnCampusUpdated. No autoriza ni avisa. Un solo insert que ignora las que ya existen (la unicidad,
 * con el campus nulo como un valor), así que es idempotente y otra sesión que abra a la vez no la hace fallar.
 */
final class CreateTermReviews
{
    public function __construct(private readonly DepartmentQuery $departments) {}

    /**
     * @param  list<Catalog>  $catalogs
     */
    public function handle(Term $term, array $catalogs): int
    {
        $now = now();
        $rows = [];

        foreach ($this->departments->activeIds() as $departmentId) {
            foreach ($catalogs as $catalog) {
                $rows[] = [
                    'term_id' => $term->getKey(),
                    'campus_id' => null,
                    'department_id' => $departmentId,
                    'catalog' => $catalog->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        return $rows === [] ? 0 : CatalogReview::query()->insertOrIgnore($rows);
    }
}
