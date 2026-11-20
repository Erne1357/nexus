<?php

namespace App\Modules\CatalogValidation\Actions;

use App\Modules\CatalogValidation\Enums\CatalogValidationPermission;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\CatalogValidation\Notifications\CatalogReviewFlagResolvedNotice;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\Core\Actions\NotifyHolders;
use App\Modules\Core\Queries\RecipientsQuery;

/**
 * Avisa al jefe que puso una observación que ya se resolvió (CV10, confirmado el 14 de noviembre de 2026;
 * CatalogReviewFlagResolvedNotice), con el puesto por el que todavía observa ese departamento (RecipientsQuery::holders
 * del permiso de observar, que solo tiene el jefe, CV-S1). Supuestos por confirmar: no avisa si la observación sigue
 * abierta, si quien la puso ya no existe, está inactivo o ya no es jefe de ese departamento (o de Servicios Escolares, CV15), ni si él mismo la resolvió.
 * Da si avisó.
 */
final class NotifyFlagResolved
{
    public function __construct(private readonly RecipientsQuery $recipients, private readonly NotifyHolders $notifyHolders) {}

    public function handle(CatalogReviewFlag $flag): bool
    {
        if ($flag->resolved_at === null || $flag->flagged_by === null || $flag->resolved_by === $flag->flagged_by) {
            return false;
        }

        $flagger = array_values(array_filter(
            $this->recipients->holders(CatalogValidationPermission::ReviewFlag, CatalogReviewQuery::targetOf($flag->review->owner_id)),
            fn (array $holder): bool => $holder['user']->getKey() === $flag->flagged_by,
        ));

        return $this->notifyHolders->handle($flagger, new CatalogReviewFlagResolvedNotice($flag))->isNotEmpty();
    }
}
