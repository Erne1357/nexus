<?php

namespace App\Modules\Faculty\Database\Factories;

use App\Modules\Faculty\Enums\EvaluationKind;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherEvaluation;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Evaluación estudiantil de 4.50 en Ene-Jun 2024.
 *
 * @extends Factory<TeacherEvaluation>
 */
#[UseModel(TeacherEvaluation::class)]
class TeacherEvaluationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'teacher_id' => Teacher::factory(),
            'term_code' => '20241',
            'kind' => EvaluationKind::Estudiantil,
            'score' => 4.5,
            'source' => 'SII',
        ];
    }

    public function estudiantil(): static
    {
        return $this->state(['kind' => EvaluationKind::Estudiantil]);
    }

    public function departamental(): static
    {
        return $this->state(['kind' => EvaluationKind::Departamental]);
    }
}
