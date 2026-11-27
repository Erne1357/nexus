<?php

namespace App\Modules\Academic\Events;

use App\Modules\Academic\Models\StudyPlan;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se dio de alta un plan de estudios (CreateStudyPlan). Sale después del commit. Un plan nuevo no
 * tiene retícula ni alumnos, así que no cambia el avance de nadie.
 */
final class StudyPlanCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public StudyPlan $studyPlan) {}
}
