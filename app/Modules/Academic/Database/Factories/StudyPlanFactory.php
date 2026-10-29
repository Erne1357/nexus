<?php

namespace App\Modules\Academic\Database\Factories;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Plan vigente de nueve semestres de una carrera.
 *
 * @extends Factory<StudyPlan>
 */
#[UseModel(StudyPlan::class)]
class StudyPlanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'career_id' => Career::factory(),
            'code' => fake()->unique()->bothify('????-2010-###'),
            'name' => 'Plan '.fake()->unique()->words(2, true),
            'version' => '2010',
            'semesters_count' => 9,
            'total_credits' => 260,
            'is_active' => true,
            'valid_from' => '2010-08-01',
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
