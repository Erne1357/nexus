<?php

namespace App\Modules\AcademicLoad;

use App\Modules\Academic\Events\DepartmentDeleting;
use App\Modules\AcademicLoad\Listeners\BlockDepartmentDeletionWithLoads;
use App\Modules\AcademicLoad\Listeners\BlockReviewReopeningAfterDraft;
use App\Modules\AcademicLoad\Listeners\CreateLoadsOnTermOpened;
use App\Modules\AcademicLoad\Listeners\ReportLoadStatuses;
use App\Modules\CatalogValidation\Events\CatalogReviewReopening;
use App\Modules\CatalogValidation\Events\LoadStatusesRequested;
use App\Modules\Core\Events\TermOpened;
use App\Modules\Core\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

/**
 * Módulo AcademicLoad, el orquestador: la carga académica de cada departamento en un campus y un periodo
 * (docs/dominio/modelo-de-datos.md, "AcademicLoad"). Depende de Academic, CatalogValidation y Demand, y de lo
 * que está a su izquierda.
 *
 * Escucha a Core, a Academic y a CatalogValidation, que no dependen de AcademicLoad: al abrir un periodo en un
 * campus crea las cargas que falten, veta borrar un departamento que tiene cargas y veta reabrir una revisión
 * de catálogo si una carga del departamento ya salió de borrador, y le dice al tablero de avance de validación
 * el estado de cada carga (LoadStatusesRequested).
 */
final class AcademicLoadServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        Event::listen(TermOpened::class, CreateLoadsOnTermOpened::class);
        Event::listen(DepartmentDeleting::class, BlockDepartmentDeletionWithLoads::class);
        Event::listen(CatalogReviewReopening::class, BlockReviewReopeningAfterDraft::class);
        Event::listen(LoadStatusesRequested::class, ReportLoadStatuses::class);
    }
}
