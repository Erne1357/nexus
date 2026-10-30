<?php

namespace App\Modules\Faculty\Database\Factories;

use App\Modules\Academic\Models\Department;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Docente activo, sin usuario, adscripciones ni plazas.
 *
 * @extends Factory<Teacher>
 */
#[UseModel(Teacher::class)]
class TeacherFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_number' => fake()->unique()->numerify('#######'),
            'user_id' => null,
            'name' => fake()->name(),
            'email' => null,
            'degree' => null,
            'hired_on' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * Con una adscripción al departamento dado (la principal, salvo que se pida lo contrario).
     */
    public function adscribedTo(Department $department, bool $primary = true): static
    {
        return $this->has(
            TeacherDepartment::factory()->for($department)->state(['is_primary' => $primary]),
            'adscriptions',
        );
    }
}
