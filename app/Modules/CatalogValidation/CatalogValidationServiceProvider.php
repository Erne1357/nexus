<?php

namespace App\Modules\CatalogValidation;

use App\Modules\CatalogValidation\Listeners\CreateReviewsOnTermOpened;
use App\Modules\CatalogValidation\Listeners\CreateRoomsReviewsOnCampusUpdated;
use App\Modules\Core\Events\CampusUpdated;
use App\Modules\Core\Events\TermOpened;
use App\Modules\Core\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

/**
 * Módulo CatalogValidation: revisión de los catálogos de cada departamento por periodo
 * (docs/dominio/modelo-de-datos.md, "CatalogValidation"). Depende de Imports y de lo que está a su izquierda.
 *
 * Escucha a Core, que no depende de CatalogValidation: al abrir un periodo en un campus crea las revisiones
 * que falten, y si un campus empieza a usar salones, las de salones de los periodos abiertos en él.
 */
final class CatalogValidationServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        Event::listen(TermOpened::class, CreateReviewsOnTermOpened::class);
        Event::listen(CampusUpdated::class, CreateRoomsReviewsOnCampusUpdated::class);
    }
}
