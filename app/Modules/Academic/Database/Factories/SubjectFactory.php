<?php

namespace App\Modules\Academic\Database\Factories;

use App\Modules\Academic\Enums\SubjectKind;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Materia básica activa de cinco créditos (3 horas de teoría y 2 de práctica), sin laboratorio.
 *
 * @extends Factory<Subject>
 */
#[UseModel(Subject::class)]
class SubjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('???-####'),
            'name' => ucfirst(fake()->unique()->words(3, true)),
            'department_id' => Department::factory(),
            'theory_hours' => 3,
            'practice_hours' => 2,
            'credits' => 5,
            'kind' => SubjectKind::Basica,
            'needs_lab' => false,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
