<?php

namespace App\Modules\Academic\Events;

use App\Modules\Academic\Models\StudyPlan;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cambiaron los datos o el estado de un plan (UpdateStudyPlan). Sale después del commit.
 */
final class StudyPlanUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<string>  $changed  columnas que cambiaron (p. ej. `['name', 'is_active']`)
     */
    public function __construct(
        public StudyPlan $studyPlan,
        public array $changed,
    ) {}
}
