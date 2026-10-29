<?php

namespace App\Modules\Academic\Database\Factories;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Carrera activa con su departamento.
 *
 * @extends Factory<Career>
 */
#[UseModel(Career::class)]
class CareerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('I???'),
            'name' => 'Ingeniería en '.fake()->unique()->words(2, true),
            'department_id' => Department::factory(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
