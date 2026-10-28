<?php

namespace App\Modules\Imports\Models;

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Database\Factories\ImportBatchFactory;
use App\Modules\Imports\Enums\BatchStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lote de importación: un archivo del SII subido para una plantilla (`template`, clave de
 * TemplateRegistry). `file_path` es relativo al disco `imports`. No se borra.
 */
#[Fillable(['template', 'campus_id', 'mapping_id', 'file_path', 'original_name', 'status', 'total_rows', 'valid_rows', 'error_rows', 'run_id', 'processed_rows', 'created_rows', 'updated_rows', 'unchanged_rows', 'failure_reason', 'uploaded_by', 'applied_by', 'applied_at'])]
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
            'processed_rows' => 'integer',
            'created_rows' => 'integer',
            'updated_rows' => 'integer',
            'unchanged_rows' => 'integer',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * El CSV con las filas con error del lote en el disco `imports`, que escribe ExportBatchErrors.
     *
     * @return Attribute<string, never>
     */
    protected function errorsPath(): Attribute
    {
        return Attribute::get(fn (): string => "{$this->id}-errores.csv");
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
     * Filas del lote, sin orden: quien las lista las ordena por `row_number`.
     *
     * @return HasMany<ImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class, 'batch_id');
    }
}
