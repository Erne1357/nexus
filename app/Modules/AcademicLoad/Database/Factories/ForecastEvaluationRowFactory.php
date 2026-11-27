<?php

namespace App\Modules\AcademicLoad\Database\Factories;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\ForecastEvaluation;
use App\Modules\AcademicLoad\Models\ForecastEvaluationRow;
use App\Modules\Core\Models\Campus;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Una fila de evaluación con lo proyectado igual a lo real (error cero); `projected`, `actual_students` y `baseline`
 * se pasan según el caso.
 *
 * @extends Factory<ForecastEvaluationRow>
 */
#[UseModel(ForecastEvaluationRow::class)]
class ForecastEvaluationRowFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $students = fake()->numberBetween(10, 60);

        return [
            'evaluation_id' => ForecastEvaluation::factory(),
            'career_id' => Career::factory(),
            'campus_id' => Campus::factory(),
            'subject_id' => Subject::factory(),
            'eligible' => $students,
            'projected' => $students,
            'suggested_groups' => 1,
            'actual_students' => $students,
            'actual_groups' => 1,
            'baseline' => $students,
            'forecast_method' => 'v1',
        ];
    }
}
