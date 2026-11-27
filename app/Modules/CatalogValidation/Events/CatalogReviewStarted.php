<?php

namespace App\Modules\CatalogValidation\Events;

use App\Modules\CatalogValidation\Models\CatalogReview;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * El jefe empezó a revisar el catálogo: la revisión pasó de pendiente a en revisión (StartReview). Sale después del commit.
 */
final class CatalogReviewStarted implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public CatalogReview $review) {}
}
