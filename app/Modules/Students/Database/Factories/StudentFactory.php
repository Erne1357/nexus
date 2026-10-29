<?php

namespace App\Modules\Students\Database\Factories;

use App\Modules\Academic\Database\Factories\CareerFactory;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Models\Campus;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Models\Student;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Alumno activo de primer semestre, con la carrera de su plan.
 *
 * @extends Factory<Student>
 */
#[UseModel(Student::class)]
class StudentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'control_number' => fake()->unique()->numerify('2#######'),
            'name' => fake()->name(),
            'study_plan_id' => StudyPlan::factory(),
            // La carrera de su plan; con un plan que no existe (una prueba de llaves), otra cualquiera.
            'career_id' => fn (array $attributes): string|CareerFactory => StudyPlan::withTrashed()->whereKey($attributes['study_plan_id'])->value('career_id') ?? Career::factory(),
            'campus_id' => Campus::factory(),
            'current_semester' => 1,
            'status' => StudentStatus::Activo,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
