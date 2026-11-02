<?php

namespace App\Modules\CatalogValidation;

use App\Modules\CatalogValidation\Listeners\CreateReviewsOnTermOpened;
use App\Modules\CatalogValidation\Listeners\CreateRoomsReviewsOnCampusUpdated;
use App\Modules\CatalogValidation\Listeners\NotifyPendingReviewsOnTermOpened;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Events\CampusUpdated;
use App\Modules\Core\Events\TermOpened;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Queries\ScopedContext;
use Illuminate\Support\Facades\Event;

/**
 * Módulo CatalogValidation: revisión de los catálogos de cada departamento por periodo
 * (docs/dominio/modelo-de-datos.md, "CatalogValidation"). Depende de Imports y de lo que está a su izquierda.
 *
 * Escucha a Core, que no depende de CatalogValidation: al abrir un periodo en un campus crea las revisiones
 * que falten y avisa a los jefes que tienen catálogos por validar, y si un campus empieza a usar salones, las de salones de los periodos abiertos en él.
 */
final class CatalogValidationServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        Event::listen(TermOpened::class, CreateReviewsOnTermOpened::class);
        Event::listen(TermOpened::class, NotifyPendingReviewsOnTermOpened::class);
        Event::listen(CampusUpdated::class, CreateRoomsReviewsOnCampusUpdated::class);
    }

    /**
     * Una entrada, en Académico, para quien revisa catálogos: el jefe de departamento y Subdirección (que, igual
     * que el admin, la abre en lectura). Que el departamento sea el suyo lo decide la pantalla.
     */
    public function navigation(): array
    {
        return [
            new NavigationItem('Validar catálogos', 'catalog-validation.review', 'o-clipboard-document-check', NavigationArea::Academico,
                visible: static fn (ScopedContext $context): bool => $context->has(Role::JefeDepartamento) || $context->has(Role::Subdireccion)),
        ];
    }
}
