<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\ModalityProfile;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Modalidad activa con el perfil de flujo por omisión y un código `M###` que no choca con los sembrados (`PRES`,
 * `EAD`, `ENL`, `TNMV`). Las pruebas que necesitan Presencial la leen del catálogo: la siembra la migración.
 *
 * @extends Factory<Modality>
 */
#[UseModel(Modality::class)]
class ModalityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('M###'),
            'name' => 'Modalidad '.fake()->word(),
            'settings' => ModalityProfile::fromArray([])->toArray(),
            'is_active' => true,
        ];
    }

    /**
     * Modalidad desactivada.
     */
    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
