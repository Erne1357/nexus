<?php

namespace App\Modules\CatalogValidation\Actions;

use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Jobs\NotifyPendingReviewsJob;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * El admin abre la validación de catálogos de un periodo (CV9, confirmada el 12 de noviembre de 2026): decide
 * cuáles catálogos se validan (ninguno, algunos o todos) y después puede abrir uno que faltó. Crea las revisiones de
 * esos catálogos (CreateTermReviews): de docentes y salones, una por departamento activo; de materias, planes y
 * carreras, una sola de Servicios Escolares para todo el Tec (CV15, confirmada el 20 de noviembre de 2026). Los salones
 * solo si alguna modalidad abierta en el periodo usa salones (CatalogReviewQuery::roomsApply), y una sola revisión por
 * departamento para todos los campus. Abrir un catálogo ya abierto no hace
 * nada (idempotente) y uno abierto no se quita del periodo (supuesto por confirmar).
 *
 * Deja una entrada en la bitácora del periodo (evento `validation_opened`) con los catálogos que abrió y cuántas
 * revisiones creó, y, ya confirmada la transacción, manda a la cola el aviso a los jefes que tienen catálogos por
 * validar (NotifyPendingReviewsJob), también al de Servicios Escolares. Sin nada nuevo no deja bitácora ni avisa. Quién puede lo decide
 * CatalogReviewPolicy::open antes de llamarla. Devuelve cuántas revisiones creó.
 */
final class OpenCatalogValidation
{
    public function __construct(
        private readonly CreateTermReviews $createTermReviews,
        private readonly CatalogReviewQuery $reviews,
    ) {}

    /**
     * @param  list<Catalog>  $catalogs
     */
    public function handle(Term $term, array $catalogs, User $actor): int
    {
        $applicable = array_values(array_filter(
            Catalog::cases(),
            fn (Catalog $catalog): bool => in_array($catalog, $catalogs, true)
                && ($catalog !== Catalog::Rooms || $this->reviews->roomsApply($term)),
        ));

        if ($applicable === []) {
            return 0;
        }

        $createdBy = DB::transaction(function () use ($term, $applicable, $actor): array {
            $createdBy = [];

            foreach ($applicable as $catalog) {
                $count = $this->createTermReviews->handle($term, [$catalog]);

                if ($count > 0) {
                    $createdBy[$catalog->value] = $count;
                }
            }

            if ($createdBy !== []) {
                $labels = array_map(fn (string $value): string => mb_strtolower(Catalog::from($value)->label()), array_keys($createdBy));

                activity()
                    ->performedOn($term)
                    ->causedBy($actor)
                    ->event('validation_opened')
                    ->withProperties(['catalogs' => array_keys($createdBy), 'reviews' => array_sum($createdBy)])
                    ->log('Abrió la validación de '.implode(', ', $labels)." en {$term->name}");
            }

            return $createdBy;
        });

        if ($createdBy !== []) {
            NotifyPendingReviewsJob::dispatch($term->getKey(), array_keys($createdBy))->afterCommit();
        }

        return array_sum($createdBy);
    }
}
