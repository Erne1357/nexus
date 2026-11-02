<?php

namespace App\Modules\CatalogValidation\Events;

use App\Modules\CatalogValidation\Models\CatalogReview;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Una revisión de catálogo quedó validada, con su foto (ValidateReview). Sale después del commit.
 */
final class CatalogReviewValidated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public CatalogReview $review) {}
}
