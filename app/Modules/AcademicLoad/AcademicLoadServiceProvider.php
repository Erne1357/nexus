<?php

namespace App\Modules\AcademicLoad;

use App\Modules\Academic\Events\DepartmentDeleting;
use App\Modules\AcademicLoad\Events\CareerProposalReturned;
use App\Modules\AcademicLoad\Events\CareerProposalSubmitted;
use App\Modules\AcademicLoad\Listeners\BlockDepartmentDeletionWithLoads;
use App\Modules\AcademicLoad\Listeners\CreateLoadsOnTermOpened;
use App\Modules\AcademicLoad\Listeners\NotifyCoordinatorsOnProposalReturned;
use App\Modules\AcademicLoad\Listeners\NotifyDepartmentsOnProposalSubmitted;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Events\TermOpened;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Queries\ScopedContext;
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
        Event::listen(CareerProposalSubmitted::class, NotifyDepartmentsOnProposalSubmitted::class);
        Event::listen(CareerProposalReturned::class, NotifyCoordinatorsOnProposalReturned::class);
    }

    /**
     * @return list<NavigationItem>
     */
    public function navigation(): array
    {
        return [
            new NavigationItem('Propuesta de carrera', 'academic-load.proposal', 'o-clipboard-document-list', NavigationArea::Academico,
                visible: static fn (ScopedContext $context): bool => $context->has(Role::Coordinador) || $context->has(Role::Subdireccion)),
        ];
    }
}
