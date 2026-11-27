<?php

namespace App\Modules\Academic\Database\Factories;

use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Una materia obligatoria del primer semestre en la retícula de un plan, sin especialidad.
 *
 * @extends Factory<StudyPlanSubject>
 */
#[UseModel(StudyPlanSubject::class)]
class StudyPlanSubjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'study_plan_id' => StudyPlan::factory(),
            'subject_id' => Subject::factory(),
            'semester' => 1,
            'specialty_id' => null,
            'is_required' => true,
        ];
    }
}
