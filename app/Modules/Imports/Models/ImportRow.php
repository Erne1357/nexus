<?php

namespace App\Modules\Imports\Models;

use App\Modules\Imports\Database\Factories\ImportRowFactory;
use App\Modules\Imports\Enums\RowStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Fila de un lote: cómo vino (`raw`), normalizada, sus errores y advertencias por campo y, ya
 * aplicada, el registro que escribió (`target`).
 */
#[Fillable(['batch_id', 'row_number', 'raw', 'normalized', 'errors', 'warnings', 'status', 'target_type', 'target_id'])]
#[UseFactory(ImportRowFactory::class)]
class ImportRow extends Model
{
    /** @use HasFactory<ImportRowFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'raw' => 'array',
            'normalized' => 'array',
            'errors' => 'array',
            'warnings' => 'array',
            'status' => RowStatus::class,
        ];
    }

    /**
     * @return BelongsTo<ImportBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'batch_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }
}
