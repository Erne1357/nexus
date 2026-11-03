<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Models\OrgUnit;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Departamento activo colgado de la Dirección (la raíz que siembra la migración).
 *
 * @extends Factory<OrgUnit>
 */
#[UseModel(OrgUnit::class)]
class OrgUnitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parent_id' => fn () => OrgUnit::query()->where('code', 'DIRECCION')->value('id'),
            'code' => fake()->unique()->bothify('U###'),
            'name' => 'Unidad '.fake()->unique()->word(),
            'kind' => OrgUnitKind::Departamento,
            'sort' => 0,
            'is_active' => true,
        ];
    }

    /**
     * Unidad desactivada.
     */
    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
