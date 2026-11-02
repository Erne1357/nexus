<?php

namespace App\Modules\AcademicLoad;

use App\Modules\Academic\Events\DepartmentDeleting;
use App\Modules\AcademicLoad\Listeners\BlockDepartmentDeletionWithLoads;
use App\Modules\AcademicLoad\Listeners\CreateLoadsOnTermOpened;
use App\Modules\Core\Events\TermOpened;
use App\Modules\Core\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

/**
 * Módulo AcademicLoad, el orquestador: la carga académica de cada departamento en un campus y un periodo
 * (docs/dominio/modelo-de-datos.md, "AcademicLoad"). Depende de Academic, CatalogValidation y Demand, y de lo
 * que está a su izquierda.
 *
 * Escucha a Core y a Academic, que no dependen de AcademicLoad: al abrir un periodo en un campus crea las
 * cargas que falten, y veta borrar un departamento que tiene cargas.
 */
final class AcademicLoadServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        Event::listen(TermOpened::class, CreateLoadsOnTermOpened::class);
        Event::listen(DepartmentDeleting::class, BlockDepartmentDeletionWithLoads::class);
    }
}
