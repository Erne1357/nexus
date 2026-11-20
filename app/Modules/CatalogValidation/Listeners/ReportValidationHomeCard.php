<?php

namespace App\Modules\CatalogValidation\Listeners;

use App\Modules\CatalogValidation\Enums\CatalogValidationPermission;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\CatalogValidation\Queries\OpenValidationQuery;
use App\Modules\CatalogValidation\Queries\ReviewOwner;
use App\Modules\CatalogValidation\Queries\ValidationProgressQuery;
use App\Modules\CatalogValidation\Queries\ValidationWindowQuery;
use App\Modules\Core\Events\HomeCardsRequested;
use App\Modules\Core\Support\HomeCard;
use App\Modules\Core\Support\StageTimeline;

/**
 * La tarjeta de Inicio de la validación de catálogos (CV-S13, supuesto por confirmar), solo mientras la validación del
 * periodo activo está abierta (OpenValidationQuery, MN-03). A quien ve el avance (Subdirección y el admin): «X de Y
 * departamentos validados», contando a Servicios Escolares (CV15) solo a quien ve sus revisiones (solo el admin, no la Subdirección ni el directivo; CV-S1 desde el 20 de noviembre de 2026), con «Ver avance». Al jefe que valida: cuántos de sus
 * catálogos le faltan en sus departamentos activos o, al de Servicios Escolares, de las materias, los planes y las
 * carreras (CV15), con la fecha límite si la etapa tiene ventana (CV11) y «Ir a validar»; si ya no le falta ninguno, no
 * hay tarjeta. Contesta HomeCardsRequested de Core sin escribir nada.
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
            $summary = $this->progress->departmentsValidated($term, CatalogReviewQuery::allowsOn($context, CatalogValidationPermission::ReviewView, ReviewOwner::SCHOOL_SERVICES));

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

        $active = $this->reviews->activeOwners()->pluck('id')->all();
        $pending = $this->reviews->ownersFor($context)
            ->filter(fn (ReviewOwner $owner): bool => in_array($owner->id, $active, true)
                && CatalogReviewQuery::allowsOn($context, CatalogValidationPermission::ReviewValidate, $owner->id))
            ->sum(fn (ReviewOwner $owner): int => count($this->reviews->pendingOf($term, $owner)));

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
