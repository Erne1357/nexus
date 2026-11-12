<?php

namespace App\Modules\CatalogValidation\Listeners;

use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\Core\Events\TermOpened;

/**
 * Al abrir un periodo en una modalidad (M4) crea las revisiones de catálogo que falten (CreateTermReviews) y lo
 * reporta: «12 revisiones de catálogo nuevas» o «1 revisión de catálogo nueva»; nada si no faltaba
 * ninguna. Corre dentro de la transacción de OpenTerm: si falla, la apertura no queda.
 */
final class CreateReviewsOnTermOpened
{
    public function __construct(private readonly CreateTermReviews $createTermReviews) {}

    public function handle(TermOpened $event): void
    {
        $created = $this->createTermReviews->handle($event->term, $event->modality);

        if ($created > 0) {
            $event->report($created === 1 ? '1 revisión de catálogo nueva' : "{$created} revisiones de catálogo nuevas");
        }
    }
}
