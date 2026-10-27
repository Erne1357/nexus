<?php

namespace App\Modules\Imports\Models;

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Database\Factories\ImportBatchFactory;
use App\Modules\Imports\Enums\BatchStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lote de importación: un archivo del SII subido para una plantilla (`template`, clave de
 * TemplateRegistry). `file_path` es relativo al disco `imports`. No se borra.
 */
#[Fillable(['template', 'campus_id', 'mapping_id', 'file_path', 'original_name', 'status', 'total_rows', 'valid_rows', 'error_rows', 'failure_reason', 'uploaded_by', 'applied_by', 'applied_at'])]
#[UseFactory(ImportBatchFactory::class)]
class ImportBatch extends Model
{
    /** @use HasFactory<ImportBatchFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BatchStatus::class,
            'total_rows' => 'integer',
            'valid_rows' => 'integer',
            'error_rows' => 'integer',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Campus, $this>
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /**
     * @return BelongsTo<ImportMapping, $this>
     */
    public function mapping(): BelongsTo
    {
        return $this->belongsTo(ImportMapping::class, 'mapping_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function applier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    /**
     * Filas en el orden del archivo.
     *
     * @return HasMany<ImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class, 'batch_id')->orderBy('row_number');
    }
}
