<?php

namespace App\Modules\CatalogValidation\Listeners;

use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\Core\Events\CampusUpdated;
use App\Modules\Core\Queries\TermQuery;

/**
 * Si un campus cambia su perfil de flujo y ahora usa salones, crea las revisiones de salones que falten en cada
 * periodo abierto en su modalidad (M4; por el puente `campuses.modality_id`) donde el admin ya abrió el catálogo de
 * salones (CV9): una por departamento y periodo, para todos los campus (decisión 6, confirmada el 12 de noviembre de
 * 2026; CreateTermReviews, que es idempotente). Los periodos que no se abrieron en su modalidad o donde los salones no
 * se abrieron no se tocan. Sale
 * después del commit de UpdateCampus (CampusUpdated).
 */
final class CreateRoomsReviewsOnCampusUpdated
{
    public function __construct(
        private readonly TermQuery $terms,
        private readonly CatalogReviewQuery $reviews,
        private readonly CreateTermReviews $createTermReviews,
    ) {}

    public function handle(CampusUpdated $event): void
    {
        $modality = $event->campus->catalogModality;

        if (! in_array('settings', $event->changed, true) || ! $event->campus->profile->usesRooms || $modality === null) {
            return;
        }

        foreach ($this->terms->openedIn($modality) as $term) {
            if (in_array(Catalog::Rooms, $this->reviews->openedCatalogs($term), true)) {
                $this->createTermReviews->handle($term, [Catalog::Rooms]);
            }
        }
    }
}
