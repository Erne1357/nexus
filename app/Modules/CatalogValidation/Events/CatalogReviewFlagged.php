<?php

namespace App\Modules\CatalogValidation\Events;

use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * El jefe puso una observación sobre un registro de una revisión (FlagRecord). Sale después del commit.
 */
final class CatalogReviewFlagged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public CatalogReviewFlag $flag) {}
}
