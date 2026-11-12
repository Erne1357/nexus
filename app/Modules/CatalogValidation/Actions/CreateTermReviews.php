<?php

namespace App\Modules\CatalogValidation\Actions;

use App\Modules\Academic\Queries\DepartmentQuery;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;

/**
 * Crea las revisiones de catálogo que falten al abrir un periodo en un campus (ver docs/dominio/reglas-de-negocio.md, Periodo y calendario, supuesto por
 * confirmar): por cada departamento activo y sin borrar, las del periodo, todas sin campus; la de salones, una para
 * todos los campus, si el perfil de este campus usa salones (decisión 6, confirmada el 12 de noviembre de 2026). Nacen
 * pendientes. Devuelve cuántas creó: 0 si no faltaba ninguna.
 *
 * Un solo insert que ignora las que ya existen (la unicidad, con el campus nulo como un valor), así que es
 * idempotente y otra sesión que abra a la vez no la hace fallar. No deja bitácora por revisión ni avisa: la
 * llama CreateReviewsOnTermOpened dentro de la transacción de OpenTerm, que deja una entrada en la bitácora
 * del periodo con el reporte.
 */
final class CreateTermReviews
{
    public function __construct(private readonly DepartmentQuery $departments) {}

    public function handle(Term $term, Campus $campus): int
    {
        $catalogs = Catalog::requiredFor($campus->profile->usesRooms);

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
