<?php

namespace App\Modules\CatalogValidation\Listeners;

use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\Core\Events\CampusUpdated;
use App\Modules\Core\Queries\TermQuery;

/**
 * Si un campus cambia su perfil de flujo y ahora usa salones, crea las revisiones de salones que falten en cada
 * periodo abierto en él (CreateTermReviews, que es idempotente): una por departamento y periodo, para todos los campus
 * (decisión 6, confirmada el 12 de noviembre de 2026); supuesto por confirmar, docs/dominio/
 * reglas-de-negocio.md, Periodo y calendario. Los periodos donde el campus no se abrió no se tocan. Sale
 * después del commit de UpdateCampus (CampusUpdated).
 */
final class CreateRoomsReviewsOnCampusUpdated
{
    public function __construct(
        private readonly TermQuery $terms,
        private readonly CreateTermReviews $createTermReviews,
    ) {}

    public function handle(CampusUpdated $event): void
    {
        if (! in_array('settings', $event->changed, true) || ! $event->campus->profile->usesRooms) {
            return;
        }

        foreach ($this->terms->openedIn($event->campus) as $term) {
            $this->createTermReviews->handle($term, $event->campus);
        }
    }
}
