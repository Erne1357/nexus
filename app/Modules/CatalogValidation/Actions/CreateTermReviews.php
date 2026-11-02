<?php

namespace App\Modules\CatalogValidation\Actions;

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;

/**
 * Crea las revisiones de catálogo que falten al abrir un periodo en un campus (S-A6, supuesto por
 * confirmar): por cada departamento activo y sin borrar, las cuatro generales del periodo, sin campus, y la
 * de salones de ese campus si su perfil usa salones (D2, confirmado el 1 de noviembre de 2026). Nacen
 * pendientes. Devuelve cuántas creó: 0 si no faltaba ninguna.
 *
 * Un solo insert que ignora las que ya existen (la unicidad, con el campus nulo como un valor), así que es
 * idempotente y otra sesión que abra a la vez no la hace fallar. No deja bitácora por revisión ni avisa: la
 * llama CreateReviewsOnTermOpened dentro de la transacción de OpenTerm, que deja una entrada en la bitácora
 * del periodo con el reporte.
 */
final class CreateTermReviews
{
    public function handle(Term $term, Campus $campus): int
    {
        $catalogs = Catalog::general();

        if ($campus->profile->usesRooms) {
            $catalogs[] = Catalog::Rooms;
        }

        $now = now();
        $rows = [];

        foreach (Department::query()->where('is_active', true)->orderBy('code')->pluck('id') as $departmentId) {
            foreach ($catalogs as $catalog) {
                $rows[] = [
                    'term_id' => $term->getKey(),
                    'campus_id' => $catalog->isGeneral() ? null : $campus->getKey(),
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
