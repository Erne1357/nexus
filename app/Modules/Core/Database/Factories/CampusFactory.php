<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Models\Campus;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Campus activo: solo el lugar (MS3), sin modalidad ni perfil de flujo.
 *
 * @extends Factory<Campus>
 */
#[UseModel(Campus::class)]
class CampusFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('C###'),
            'name' => 'Campus '.fake()->unique()->city(),
            'is_active' => true,
        ];
    }

    /**
     * Campus desactivado: no se ofrece en el selector y sus alcances no cuentan.
     */
    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
