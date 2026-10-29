<?php

namespace App\Modules\Academic\Database\Factories;

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\SubjectPrerequisite;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Seriación de una materia de segundo semestre con otra de primero, las dos puestas en la retícula del plan
 * (la base no acepta una seriación con materias fuera de la retícula). Con `subject_id` y
 * `prerequisite_subject_id` propios, esas materias ya tienen que estar en el plan.
 *
 * @extends Factory<SubjectPrerequisite>
 */
#[UseModel(SubjectPrerequisite::class)]
class SubjectPrerequisiteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'study_plan_id' => StudyPlan::factory(),
            'subject_id' => fn (array $attributes): string => StudyPlanSubject::factory()->create(['study_plan_id' => $attributes['study_plan_id'], 'semester' => 2])->subject_id,
            'prerequisite_subject_id' => fn (array $attributes): string => StudyPlanSubject::factory()->create(['study_plan_id' => $attributes['study_plan_id']])->subject_id,
            'kind' => PrerequisiteKind::Seriacion,
        ];
    }
}
