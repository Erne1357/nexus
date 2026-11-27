<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\PositionMailbox;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * El buzón de la jefatura de una unidad nueva, activo.
 *
 * @extends Factory<PositionMailbox>
 */
#[UseModel(PositionMailbox::class)]
class PositionMailboxFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'role' => 'jefe_departamento',
            'org_unit_id' => OrgUnit::factory(),
            'career_id' => null,
            'campus_id' => null,
            'modality_id' => null,
            'email' => 'jefatura_'.fake()->unique()->lexify('????').'@cdjuarez.tecnm.mx',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
