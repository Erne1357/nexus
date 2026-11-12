<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Enums\Modality;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\Modality as CatalogModality;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Campus presencial activo con el perfil de flujo por omisión, ligado a su modalidad del catálogo por el puente
 * `modality_id` (ADR-0025; se quita al contraer). El catálogo lo siembra su migración.
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
            'modality_id' => function (array $attributes): ?string {
                $modality = $attributes['modality'] instanceof Modality ? $attributes['modality'] : Modality::from($attributes['modality']);

                return CatalogModality::withTrashed()->where('code', $modality->catalogCode())->value('id');
            },
            'settings' => CampusProfile::fromArray([])->toArray(),
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
