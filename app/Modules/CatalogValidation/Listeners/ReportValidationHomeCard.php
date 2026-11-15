<?php

namespace App\Modules\CatalogValidation\Listeners;

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Enums\CatalogValidationPermission;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\CatalogValidation\Queries\OpenValidationQuery;
use App\Modules\CatalogValidation\Queries\ValidationProgressQuery;
use App\Modules\CatalogValidation\Queries\ValidationWindowQuery;
use App\Modules\Core\Events\HomeCardsRequested;
use App\Modules\Core\Support\HomeCard;
use App\Modules\Core\Support\StageTimeline;

/**
 * La tarjeta de Inicio de la validación de catálogos (CV-S13, supuesto por confirmar), solo mientras la validación del
 * periodo activo está abierta (OpenValidationQuery, MN-03). A quien ve el avance (Subdirección y el admin): «X de Y
 * departamentos validados» con «Ver avance». Al jefe que valida: cuántos catálogos le faltan en sus departamentos
 * activos, con la fecha límite si la etapa tiene ventana (CV11) y «Ir a validar»; si ya no le falta ninguno, no hay
 * tarjeta. Contesta HomeCardsRequested de Core sin escribir nada.
 */
final class ReportValidationHomeCard
{
    public function __construct(
        private readonly OpenValidationQuery $openValidation,
        private readonly ValidationProgressQuery $progress,
        private readonly CatalogReviewQuery $reviews,
        private readonly ValidationWindowQuery $window,
    ) {}

    public function handle(HomeCardsRequested $event): void
    {
        $term = $event->term;
        $context = $event->context;

        if ($term === null || ! $this->openValidation->existsIn($term)) {
            return;
        }

        if ($context->allowsAny(CatalogValidationPermission::ProgressView)) {
            $summary = $this->progress->departmentsValidated($term);

            $event->add(new HomeCard(
                key: 'catalog-validation',
                title: 'Validación de catálogos',
                body: "{$summary['validated']} de {$summary['total']} departamentos validados",
                actionLabel: 'Ver avance',
                url: route('catalog-validation.progress'),
                icon: 'o-chart-bar-square',
            ));

            return;
        }

        $pending = $this->reviews->departmentsFor($context)
            ->filter(fn (Department $department): bool => $department->is_active
                && CatalogReviewQuery::allowsOnDepartment($context, CatalogValidationPermission::ReviewValidate, $department->getKey()))
            ->sum(fn (Department $department): int => count($this->reviews->pendingFor($term, null, $department)));

        if ($pending === 0) {
            return;
        }

        $deadline = $this->window->deadline($term);

        $event->add(new HomeCard(
            key: 'catalog-validation',
            title: 'Validación de catálogos',
            body: ($pending === 1 ? 'Tienes 1 catálogo por validar' : "Tienes {$pending} catálogos por validar").($deadline === null ? '' : ' · fecha límite '.StageTimeline::dateLabel($deadline)),
            actionLabel: 'Ir a validar',
            url: route('catalog-validation.review'),
            icon: 'o-clipboard-document-check',
        ));
    }
}
