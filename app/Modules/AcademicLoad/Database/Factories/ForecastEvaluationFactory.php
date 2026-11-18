<?php

namespace App\Modules\AcademicLoad\Database\Factories;

use App\Modules\AcademicLoad\Enums\ForecastEvaluationStatus;
use App\Modules\AcademicLoad\Models\ForecastEvaluation;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Una evaluación lista (`done`) en Presencial, de todos los campus; `queued()`, `running()` y `failed()` para los otros
 * estados.
 *
 * @extends Factory<ForecastEvaluation>
 */
#[UseModel(ForecastEvaluation::class)]
class ForecastEvaluationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'term_id' => Term::factory(),
            'modality_id' => fn (): string => Modality::withTrashed()->where('code', 'PRES')->value('id') ?? Modality::factory()->create()->id,
            'campus_id' => null,
            'status' => ForecastEvaluationStatus::Done,
            'method' => 'v1',
            'history_terms' => 4,
            'totals' => null,
            'started_at' => fn (): CarbonImmutable => CarbonImmutable::now()->startOfSecond(),
            'finished_at' => fn (): CarbonImmutable => CarbonImmutable::now()->startOfSecond(),
            'progress' => 100,
        ];
    }

    public function queued(): static
    {
        return $this->state(['status' => ForecastEvaluationStatus::Queued, 'started_at' => null, 'finished_at' => null, 'progress' => 0]);
    }

    public function running(): static
    {
        return $this->state(['status' => ForecastEvaluationStatus::Running, 'finished_at' => null, 'progress' => 0]);
    }

    public function failed(string $error = 'No hay kárdex de ese periodo'): static
    {
        return $this->state(['status' => ForecastEvaluationStatus::Failed, 'error' => $error]);
    }
}
