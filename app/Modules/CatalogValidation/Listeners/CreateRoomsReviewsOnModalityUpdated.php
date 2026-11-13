<?php

namespace App\Modules\CatalogValidation\Listeners;

use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\Core\Events\ModalityUpdated;
use App\Modules\Core\Queries\TermQuery;

/**
 * Si una modalidad cambia su perfil de flujo y ahora usa salones, crea las revisiones de salones que falten en cada
 * periodo abierto en ella (M4) donde el admin ya abrió el catálogo de salones (CV9): una por departamento y periodo,
 * para todos los campus (decisión 6, confirmada el 12 de noviembre de 2026; CreateTermReviews, que es idempotente). Los
 * periodos que no se abrieron en ella o donde los salones no se abrieron no se tocan. Sale después del commit de
 * UpdateModality (ModalityUpdated). El perfil es de la modalidad, no del campus (MS1, MS3).
 */
final class CreateRoomsReviewsOnModalityUpdated
{
    public function __construct(
        private readonly TermQuery $terms,
        private readonly CatalogReviewQuery $reviews,
        private readonly CreateTermReviews $createTermReviews,
    ) {}

    public function handle(ModalityUpdated $event): void
    {
        if (! in_array('settings', $event->changed, true) || ! $event->modality->profile->usesRooms) {
            return;
        }

        foreach ($this->terms->openedIn($event->modality) as $term) {
            if (in_array(Catalog::Rooms, $this->reviews->openedCatalogs($term), true)) {
                $this->createTermReviews->handle($term, [Catalog::Rooms]);
            }
        }
    }
}
