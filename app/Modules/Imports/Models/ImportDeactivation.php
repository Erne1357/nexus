<?php

namespace App\Modules\Imports\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Un registro del catálogo (`target`) que un lote desactivó por no venir en el archivo, con "desactivar
 * faltantes" (tarea 1.3). Lo escribe ApplyImportBatch y no cambia después.
 */
#[Fillable(['batch_id', 'target_type', 'target_id'])]
class ImportDeactivation extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

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
