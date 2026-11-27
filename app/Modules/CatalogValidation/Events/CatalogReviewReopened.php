<?php

namespace App\Modules\CatalogValidation\Events;

use App\Modules\CatalogValidation\Models\CatalogReview;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Una revisión validada se reabrió y quedó en revisión (ReopenReview). Sale después del commit.
 */
final class CatalogReviewReopened implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public CatalogReview $review) {}
}
