<?php

namespace App\Modules\Faculty\Database\Factories;

use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherExternalLoad;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Diez horas de un origen único en un periodo nuevo.
 *
 * @extends Factory<TeacherExternalLoad>
 */
#[UseModel(TeacherExternalLoad::class)]
class TeacherExternalLoadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'teacher_id' => Teacher::factory(),
            'term_id' => Term::factory(),
            'source' => 'TecNM Virtual '.fake()->unique()->numerify('###'),
            'hours' => 10,
            'notes' => null,
        ];
    }
}
