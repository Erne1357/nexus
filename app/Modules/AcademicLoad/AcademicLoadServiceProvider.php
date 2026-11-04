<?php

namespace App\Modules\AcademicLoad;

use App\Modules\Academic\Events\DepartmentDeleting;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Events\CareerProposalReturned;
use App\Modules\AcademicLoad\Events\CareerProposalSubmitted;
use App\Modules\AcademicLoad\Listeners\BlockDepartmentDeletionWithLoads;
use App\Modules\AcademicLoad\Listeners\BlockReviewReopeningAfterDraft;
use App\Modules\AcademicLoad\Listeners\CreateLoadsOnTermOpened;
use App\Modules\AcademicLoad\Listeners\NotifyCoordinatorsOnProposalReturned;
use App\Modules\AcademicLoad\Listeners\NotifyDepartmentsOnProposalSubmitted;
use App\Modules\AcademicLoad\Listeners\ReportLoadStatuses;
use App\Modules\CatalogValidation\Events\CatalogReviewReopening;
use App\Modules\CatalogValidation\Events\LoadStatusesRequested;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Events\TermOpened;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;
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
                visible: static fn (Actor $context): bool => $context->allowsAny(AcademicLoadPermission::ProposalView)),
            new NavigationItem('Bandeja del departamento', 'academic-load.inbox', 'o-inbox-arrow-down', NavigationArea::Academico,
                visible: static fn (Actor $context): bool => $context->allowsAny(AcademicLoadPermission::ProposalReviewView)),
        ];
    }

    public function permissions(): array
    {
        return [AcademicLoadPermission::class];
    }

    /**
     * Subdirección y el jefe de proyecto docente ven la carga y la bandeja (Subdirección, además, la propuesta); el
     * jefe de departamento, además, propone la carga y decide la parte de su departamento; el coordinador arma,
     * corrige, envía y ve la propuesta de su carrera.
     */
    public function grants(): array
    {
        return [
            Role::Subdireccion->value => [
                AcademicLoadPermission::LoadView,
                AcademicLoadPermission::ProposalView,
                AcademicLoadPermission::ProposalReviewView,
            ],
            Role::JefeDepartamento->value => [
                AcademicLoadPermission::LoadView,
                AcademicLoadPermission::LoadPropose,
                AcademicLoadPermission::ProposalReviewView,
                AcademicLoadPermission::ProposalReviewDecide,
            ],
            Role::JefeProyectoDocente->value => [
                AcademicLoadPermission::LoadView,
                AcademicLoadPermission::ProposalReviewView,
            ],
            Role::Coordinador->value => [
                AcademicLoadPermission::ProposalCreate,
                AcademicLoadPermission::ProposalUpdate,
                AcademicLoadPermission::ProposalSubmit,
                AcademicLoadPermission::ProposalView,
            ],
        ];
    }
}
