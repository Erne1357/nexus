<?php

namespace App\Modules\Imports\Database\Factories;

use App\Modules\Core\Models\User;
use App\Modules\Imports\Models\ImportMapping;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Mapeo de la plantilla de prueba con nombres de columna como los de una exportación del SII.
 *
 * @extends Factory<ImportMapping>
 */
#[UseModel(ImportMapping::class)]
class ImportMappingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'template' => 'test-campuses',
            'name' => 'SII '.fake()->unique()->numerify('####'),
            'columns' => ['CVE' => 'code', 'NOMBRE' => 'name', 'MODALIDAD' => 'modality'],
            'created_by' => User::factory(),
        ];
    }
}
