<?php

namespace App\Modules\Communication\Models;

use App\Modules\Communication\Database\Factories\PhotoBatchFactory;
use App\Modules\Communication\Enums\PhotoBatchKind;
use App\Modules\Communication\Enums\PhotoBatchStatus;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un lote de fotos en ZIP (CO-S5). `report` trae un renglón por archivo, `{file, key, outcome, message}` (outcome es un
 * PhotoOutcome); `total` son los archivos del ZIP, `processed` los que ya se vieron y `applied` los aplicados.
 */
#[Fillable(['kind', 'status', 'file_path', 'original_name', 'total', 'processed', 'applied', 'report', 'error', 'uploaded_by', 'started_at', 'finished_at'])]
#[UseFactory(PhotoBatchFactory::class)]
class PhotoBatch extends Model
{
    /** @use HasFactory<PhotoBatchFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'kind' => PhotoBatchKind::class,
            'status' => PhotoBatchStatus::class,
            'total' => 'integer',
            'processed' => 'integer',
            'applied' => 'integer',
            'report' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
