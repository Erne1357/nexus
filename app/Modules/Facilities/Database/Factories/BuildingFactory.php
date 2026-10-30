<?php

namespace App\Modules\Facilities\Database\Factories;

use App\Modules\Core\Models\Campus;
use App\Modules\Facilities\Models\Building;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Edificio activo de un campus.
 *
 * @extends Factory<Building>
 */
#[UseModel(Building::class)]
class BuildingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campus_id' => Campus::factory(),
            'code' => fake()->unique()->bothify('E###'),
            'name' => 'Edificio '.fake()->unique()->words(2, true),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
