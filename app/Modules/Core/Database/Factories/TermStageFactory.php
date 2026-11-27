<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermStage;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Ventana general (sin modalidad) de la propuesta de un periodo nuevo.
 *
 * @extends Factory<TermStage>
 */
#[UseModel(TermStage::class)]
class TermStageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'term_id' => Term::factory(),
            'modality_id' => null,
            'stage' => Stage::Proposal,
            'starts_on' => '2026-11-02',
            'ends_on' => '2026-11-13',
        ];
    }
}
