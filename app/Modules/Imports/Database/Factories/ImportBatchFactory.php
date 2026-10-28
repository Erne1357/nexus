<?php

namespace App\Modules\Imports\Database\Factories;

use App\Modules\Core\Models\User;
use App\Modules\Imports\Enums\BatchStatus;
use App\Modules\Imports\Models\ImportBatch;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Lote recién subido de la plantilla de prueba (`test-campuses`), sin campus ni mapeo.
 *
 * @extends Factory<ImportBatch>
 */
#[UseModel(ImportBatch::class)]
class ImportBatchFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'template' => 'test-campuses',
            'file_path' => Str::uuid7().'.csv',
            'original_name' => fake()->word().'.csv',
            'status' => BatchStatus::Uploaded,
            'uploaded_by' => User::factory(),
        ];
    }
}
