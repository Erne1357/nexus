<?php

namespace App\Modules\Academic\Database\Factories;

use App\Modules\Academic\Models\Department;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Departamento activo.
 *
 * @extends Factory<Department>
 */
#[UseModel(Department::class)]
class DepartmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('D###'),
            'name' => 'Departamento de '.fake()->unique()->words(2, true),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
