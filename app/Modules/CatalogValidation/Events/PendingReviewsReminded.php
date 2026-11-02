<?php

namespace App\Modules\CatalogValidation\Events;

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Term;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Subdirección o el admin recordó a los jefes de un departamento sus catálogos por validar (RemindPendingReviews).
 */
final class PendingReviewsReminded implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Term $term, public Department $department, public int $notified) {}
}
