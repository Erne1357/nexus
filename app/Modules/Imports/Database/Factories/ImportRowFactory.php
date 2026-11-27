<?php

namespace App\Modules\Imports\Database\Factories;

use App\Modules\Imports\Enums\RowStatus;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fila válida de la plantilla de prueba, sin aplicar.
 *
 * @extends Factory<ImportRow>
 */
#[UseModel(ImportRow::class)]
class ImportRowFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = fake()->unique()->bothify('C###');

        return [
            'batch_id' => ImportBatch::factory(),
            'row_number' => fake()->unique()->numberBetween(2, 100000),
            'raw' => ['code' => $code],
            'normalized' => ['code' => $code],
            'status' => RowStatus::Valid,
        ];
    }
}
