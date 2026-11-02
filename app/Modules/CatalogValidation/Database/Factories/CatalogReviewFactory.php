<?php

namespace App\Modules\CatalogValidation\Database\Factories;

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Revisión pendiente del catálogo general de docentes de un departamento en un periodo, sin campus.
 *
 * @extends Factory<CatalogReview>
 */
#[UseModel(CatalogReview::class)]
class CatalogReviewFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'term_id' => Term::factory(),
            'campus_id' => null,
            'department_id' => Department::factory(),
            'catalog' => Catalog::Teachers,
            'status' => ReviewStatus::Pending,
        ];
    }

    /**
     * La revisión de un catálogo general, sin campus.
     */
    public function general(Catalog $catalog): static
    {
        return $this->state(['catalog' => $catalog, 'campus_id' => null]);
    }

    /**
     * La revisión de salones de un campus (uno nuevo si no se da).
     */
    public function rooms(?Campus $campus = null): static
    {
        return $this->state(['catalog' => Catalog::Rooms, 'campus_id' => $campus?->getKey() ?? Campus::factory()]);
    }

    public function inReview(): static
    {
        return $this->state(['status' => ReviewStatus::InReview]);
    }

    public function validated(): static
    {
        return $this->state([
            'status' => ReviewStatus::Validated,
            'validated_by' => User::factory(),
            'validated_at' => now(),
        ]);
    }
}
