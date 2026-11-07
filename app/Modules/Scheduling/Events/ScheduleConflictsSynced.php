<?php

namespace App\Modules\Scheduling\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se recalcularon los choques de la carga de un campus en un periodo (SyncScheduleConflicts): el tablero puede
 * refrescar sus conteos. Sale después del commit.
 */
final class ScheduleConflictsSynced implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public string $termId,
        public string $campusId,
    ) {}
}
