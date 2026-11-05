<?php

namespace App\Modules\Academic\Database\Factories;

use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubjectPending;
use App\Modules\Academic\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Un pendiente sin resolver, sin especialidad.
 *
 * @extends Factory<StudyPlanSubjectPending>
 */
#[UseModel(StudyPlanSubjectPending::class)]
class StudyPlanSubjectPendingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'study_plan_id' => StudyPlan::factory(),
            'subject_id' => Subject::factory(),
            'specialty_id' => null,
            'source' => 'sii',
        ];
    }
}
