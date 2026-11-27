<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Enums\WaitingPrerequisiteOutcome;
use App\Modules\Academic\Models\StudyPlanPrerequisitePending;

/**
 * Lo que ApplyWaitingPrerequisites hizo con un requisito que esperaba: la fila (con sus materias en `subject` y
 * `prerequisite`), qué pasó y el tipo que importa para avisarlo: el que tiene en la seriación si entró o ya estaba, y si
 * no entró, el suyo.
 */
final readonly class WaitingPrerequisiteResult
{
    public function __construct(
        public StudyPlanPrerequisitePending $waiting,
        public WaitingPrerequisiteOutcome $outcome,
        public PrerequisiteKind $kind,
    ) {}
}
