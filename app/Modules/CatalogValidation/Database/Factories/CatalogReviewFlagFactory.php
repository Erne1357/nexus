<?php

namespace App\Modules\CatalogValidation\Database\Factories;

use App\Modules\CatalogValidation\Enums\ReviewFlag;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Observación abierta «debe desactivarse» sobre un docente, en una revisión pendiente de docentes.
 *
 * @extends Factory<CatalogReviewFlag>
 */
#[UseModel(CatalogReviewFlag::class)]
class CatalogReviewFlagFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'review_id' => CatalogReview::factory(),
            'flaggable_type' => (new Teacher)->getMorphClass(),
            'flaggable_id' => Teacher::factory(),
            'flag' => ReviewFlag::Inactive,
            'comment' => null,
            'flagged_by' => User::factory(),
        ];
    }

    /**
     * Sobre un registro dado.
     */
    public function on(Model $record): static
    {
        return $this->state(['flaggable_type' => $record->getMorphClass(), 'flaggable_id' => $record->getKey()]);
    }

    public function resolved(): static
    {
        return $this->state(['resolved_at' => now(), 'resolved_by' => User::factory()]);
    }
}
