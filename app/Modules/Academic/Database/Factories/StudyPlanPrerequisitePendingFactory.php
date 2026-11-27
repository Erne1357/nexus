<?php

namespace App\Modules\Academic\Database\Factories;

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanPrerequisitePending;
use App\Modules\Academic\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Un requisito del SII que espera, sin aplicar: una seriación entre dos materias nuevas, que no están en la retícula
 * del plan ni en «Por validar». Las pruebas que lo aplican ponen sus materias donde las necesitan.
 *
 * @extends Factory<StudyPlanPrerequisitePending>
 */
#[UseModel(StudyPlanPrerequisitePending::class)]
class StudyPlanPrerequisitePendingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'study_plan_id' => StudyPlan::factory(),
            'subject_id' => Subject::factory(),
            'prerequisite_subject_id' => Subject::factory(),
            'kind' => PrerequisiteKind::Seriacion,
            'source' => 'sii',
        ];
    }
}
