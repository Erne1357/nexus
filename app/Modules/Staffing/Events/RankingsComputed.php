<?php

namespace App\Modules\Staffing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se recalculó el ranking docente de los grupos de un departamento en un periodo y campus (RankGroups): la pantalla
 * de asignación (tarea 3.9) puede refrescarse. Sale después del commit.
 */
final class RankingsComputed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public string $termId,
        public string $campusId,
        public string $departmentId,
    ) {}
}
