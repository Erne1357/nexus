<?php

namespace App\Modules\CatalogValidation\Database\Factories;

use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewMark;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Marca de revisado de un docente en una revisión pendiente de docentes, de ahora.
 *
 * @extends Factory<CatalogReviewMark>
 */
#[UseModel(CatalogReviewMark::class)]
class CatalogReviewMarkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'catalog_review_id' => CatalogReview::factory(),
            'record_id' => Teacher::factory(),
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
        ];
    }

    /**
     * Sobre un registro dado.
     */
    public function on(Model $record): static
    {
        return $this->state(['record_id' => $record->getKey()]);
    }
}
