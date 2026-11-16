<?php

namespace App\Modules\Demand\Database\Factories;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Demand\Enums\ForecastMethod;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Demand\Rules\DemandForecaster;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Una materia presencial en un campus nuevo con historia propia: 60 elegibles (5 de ellos cursan la seriación), 20
 * cursándola, no inscripción de 0.2 y reprobación de 0.25 en 4 periodos, que dan 5 repetidores, 53 esperados y 2 grupos
 * (DemandForecaster v1 con 35 por grupo).
 *
 * @extends Factory<DemandForecast>
 */
#[UseModel(DemandForecast::class)]
class DemandForecastFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'term_id' => Term::factory(),
            'modality_id' => fn (): ?string => Modality::withTrashed()->where('code', 'PRES')->value('id'),
            'campus_id' => Campus::factory(),
            'career_id' => Career::factory(),
            'subject_id' => Subject::factory(),
            'eligible_students' => 60,
            'pending_prerequisite_students' => 5,
            'current_students' => 20,
            'new_students' => null,
            'non_enrollment_rate' => 0.2,
            'failure_rate' => 0.25,
            'history_terms_used' => 4,
            'expected_repeaters' => 5,
            'expected_students' => 53,
            'suggested_groups' => 2,
            'method' => ForecastMethod::Formula,
            'formula_version' => DemandForecaster::VERSION,
            'needs_review' => false,
            'computed_at' => now(),
        ];
    }

    /**
     * La única columna de una modalidad sin salones (MS5).
     */
    public function withoutCampus(): static
    {
        return $this->state(['campus_id' => null]);
    }

    /**
     * Una materia sin historia propia ni de su departamento: los esperados son los elegibles.
     */
    public function withoutHistory(): static
    {
        return $this->state([
            'non_enrollment_rate' => null,
            'failure_rate' => null,
            'history_terms_used' => 0,
            'expected_repeaters' => 0,
            'expected_students' => 60,
            'method' => ForecastMethod::NoHistory,
        ]);
    }

    /**
     * Una materia de primer semestre: los rezagados más el nuevo ingreso histórico.
     */
    public function firstSemester(int $newStudents = 140): static
    {
        return $this->state(fn (array $attributes): array => [
            'new_students' => $newStudents,
            'expected_students' => $attributes['expected_students'] + $newStudents,
            'method' => ForecastMethod::FirstSemester,
        ]);
    }
}
