<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Enums\Modality;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Campus presencial activo con el perfil de flujo por omisión.
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
            'modality' => Modality::Presencial,
            'settings' => CampusProfile::fromArray([])->toArray(),
            'is_active' => true,
        ];
    }
}
