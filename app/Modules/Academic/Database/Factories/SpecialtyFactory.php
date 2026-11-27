<?php

namespace App\Modules\Academic\Database\Factories;

use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Especialidad activa de un plan.
 *
 * @extends Factory<Specialty>
 */
#[UseModel(Specialty::class)]
class SpecialtyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'study_plan_id' => StudyPlan::factory(),
            'code' => fake()->unique()->bothify('ESP-###'),
            'name' => 'Especialidad en '.fake()->unique()->words(2, true),
            'is_active' => true,
        ];
    }
}
