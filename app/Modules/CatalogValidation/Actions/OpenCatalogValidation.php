<?php

namespace App\Modules\CatalogValidation\Actions;

use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Jobs\NotifyPendingReviewsJob;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * El admin abre la validación de catálogos de un periodo (CV9, confirmada el 12 de noviembre de 2026): decide
 * cuáles catálogos se validan (ninguno, algunos o todos) y después puede abrir uno que faltó. Crea las revisiones de
 * esos catálogos para cada departamento activo (CreateTermReviews); los salones solo si algún campus abierto en el
 * periodo usa salones, y una sola revisión por departamento para todos los campus. Abrir un catálogo ya abierto no hace
 * nada (idempotente) y uno abierto no se quita del periodo (supuesto por confirmar).
 *
 * Deja una entrada en la bitácora del periodo (evento `validation_opened`) con los catálogos que abrió y cuántas
 * revisiones creó, y, ya confirmada la transacción, manda a la cola el aviso a los jefes que tienen catálogos por
 * validar (NotifyPendingReviewsJob). Sin nada nuevo no deja bitácora ni avisa. Quién puede lo decide
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

        $created = DB::transaction(function () use ($term, $applicable, $actor): int {
            $created = $this->createTermReviews->handle($term, $applicable);

            if ($created > 0) {
                activity()
                    ->performedOn($term)
                    ->causedBy($actor)
                    ->event('validation_opened')
                    ->withProperties(['catalogs' => array_map(fn (Catalog $catalog): string => $catalog->value, $applicable), 'reviews' => $created])
                    ->log('Abrió la validación de '.implode(', ', array_map(fn (Catalog $catalog): string => mb_strtolower($catalog->label()), $applicable))." en {$term->name}");
            }

            return $created;
        });

        if ($created > 0) {
            NotifyPendingReviewsJob::dispatch($term->getKey(), TermOpening::query()->where('term_id', $term->getKey())->pluck('campus_id')->all());
        }

        return $created;
    }
}
