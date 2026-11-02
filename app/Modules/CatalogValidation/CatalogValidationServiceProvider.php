<?php

namespace App\Modules\CatalogValidation;

use App\Modules\CatalogValidation\Listeners\CreateReviewsOnTermOpened;
use App\Modules\CatalogValidation\Listeners\CreateRoomsReviewsOnCampusUpdated;
use App\Modules\CatalogValidation\Listeners\NotifyPendingReviewsOnTermOpened;
use App\Modules\CatalogValidation\Listeners\StartTeachersReviewOnFacultyCapture;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Events\CampusUpdated;
use App\Modules\Core\Events\TermOpened;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Faculty\Events\AdscriptionChanged;
use App\Modules\Faculty\Events\AvailabilityChanged;
use App\Modules\Faculty\Events\ExternalLoadChanged;
use App\Modules\Faculty\Events\PositionChanged;
use App\Modules\Faculty\Events\QualificationChanged;
use App\Modules\Faculty\Events\TeacherUpdated;
use Illuminate\Support\Facades\Event;

/**
 * Módulo CatalogValidation: revisión de los catálogos de cada departamento por periodo
 * (docs/dominio/modelo-de-datos.md, "CatalogValidation"). Depende de Imports y de lo que está a su izquierda.
 *
 * Escucha a Core, que no depende de CatalogValidation: al abrir un periodo en un campus crea las revisiones
 * que falten y avisa a los jefes que tienen catálogos por validar, y si un campus empieza a usar salones, las de
 * salones de los periodos abiertos en él. Escucha a Faculty: la captura del jefe sobre un docente suyo inicia la
 * revisión de docentes (CV-S4).
 */
final class CatalogValidationServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        Event::listen(TermOpened::class, CreateReviewsOnTermOpened::class);
        Event::listen(TermOpened::class, NotifyPendingReviewsOnTermOpened::class);
        Event::listen(CampusUpdated::class, CreateRoomsReviewsOnCampusUpdated::class);

        foreach ([AvailabilityChanged::class, QualificationChanged::class, ExternalLoadChanged::class, TeacherUpdated::class, AdscriptionChanged::class, PositionChanged::class] as $event) {
            Event::listen($event, StartTeachersReviewOnFacultyCapture::class);
        }
    }

    /**
     * «Validar catálogos», en Académico, para quien revisa catálogos: el jefe de departamento y Subdirección (que, igual
     * que el admin, la abre en lectura). Que el departamento sea el suyo lo decide la pantalla.
     * «Avance de validación», para Subdirección y el admin (tarea 2.5; `has()` incluye al admin).
     */
    public function navigation(): array
    {
        return [
            new NavigationItem('Validar catálogos', 'catalog-validation.review', 'o-clipboard-document-check', NavigationArea::Academico,
                visible: static fn (ScopedContext $context): bool => $context->has(Role::JefeDepartamento) || $context->has(Role::Subdireccion)),
            new NavigationItem('Avance de validación', 'catalog-validation.progress', 'o-chart-bar-square', NavigationArea::Academico,
                visible: static fn (ScopedContext $context): bool => $context->has(Role::Subdireccion)),
        ];
    }
}
