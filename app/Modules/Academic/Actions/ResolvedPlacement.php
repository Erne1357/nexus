<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Models\StudyPlanSubject;

/**
 * Lo que deja ResolvePendingPlacement: el lugar de la materia en la retícula (nuevo, restaurado o el que ya tenía) y,
 * por cada requisito del SII que esperaba y pudo tocar, qué pasó con él (ApplyWaitingPrerequisites), en el orden en que
 * se aplicaron.
 */
final readonly class ResolvedPlacement
{
    /**
     * @param  list<WaitingPrerequisiteResult>  $prerequisites
     */
    public function __construct(
        public StudyPlanSubject $placement,
        public array $prerequisites,
    ) {}
}
