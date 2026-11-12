<?php

namespace App\Modules\CatalogValidation\Listeners;

use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Events\CatalogReviewValidated;
use App\Modules\CatalogValidation\Jobs\InviteReviewTeachersJob;

/**
 * Al validar la revisión de docentes de un departamento, encola las invitaciones de acceso de sus docentes
 * (InviteReviewTeachersJob, AC-09). Las revisiones de otros catálogos no invitan a nadie. Sale después del commit de
 * ValidateReview.
 */
final class InviteTeachersOnReviewValidated
{
    public function handle(CatalogReviewValidated $event): void
    {
        if ($event->review->catalog === Catalog::Teachers) {
            InviteReviewTeachersJob::dispatch($event->review->getKey());
        }
    }
}
