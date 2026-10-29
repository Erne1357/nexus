<?php

namespace App\Modules\Students\Database\Factories;

use App\Modules\Students\Models\Student;
use App\Modules\Students\Models\StudentProgress;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Progreso de un alumno que todavía no aprueba nada, en el plan del alumno.
 *
 * @extends Factory<StudentProgress>
 */
#[UseModel(StudentProgress::class)]
class StudentProgressFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'study_plan_id' => fn (array $attributes): string => Student::query()->findOrFail($attributes['student_id'])->study_plan_id,
            'credits_approved' => 0,
            'subjects_approved' => 0,
            'subjects_pending' => [],
            'subjects_available' => [],
            'computed_at' => now(),
        ];
    }
}
