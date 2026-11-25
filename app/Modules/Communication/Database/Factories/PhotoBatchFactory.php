<?php

namespace App\Modules\Communication\Database\Factories;

use App\Modules\Communication\Enums\PhotoBatchKind;
use App\Modules\Communication\Enums\PhotoBatchStatus;
use App\Modules\Communication\Models\PhotoBatch;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Un lote de fotos del personal en cola; `status()` para los otros estados.
 *
 * @extends Factory<PhotoBatch>
 */
#[UseModel(PhotoBatch::class)]
class PhotoBatchFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => PhotoBatchKind::Personal,
            'status' => PhotoBatchStatus::EnCola,
            'file_path' => 'photo-batches/'.fake()->uuid().'.zip',
            'original_name' => 'fotos.zip',
            'total' => 0,
            'processed' => 0,
            'applied' => 0,
            'uploaded_by' => User::factory(),
        ];
    }

    public function status(PhotoBatchStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
