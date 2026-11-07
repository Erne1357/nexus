<?php

namespace App\Modules\Staffing\Database\Factories;

use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Staffing\Models\TeacherTermLoad;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Docente con 10 horas de base y 4 asignadas en 2 grupos, sin externas, con tope de 12.
 *
 * @extends Factory<TeacherTermLoad>
 */
#[UseModel(TeacherTermLoad::class)]
class TeacherTermLoadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'teacher_id' => Teacher::factory(),
            'term_id' => Term::factory(),
            'base_hours' => 10,
            'external_hours' => 0,
            'assigned_hours' => 4,
            'limit_hours' => 12,
            'remaining_hours' => 8,
            'groups_count' => 2,
            'computed_at' => now(),
        ];
    }
}
