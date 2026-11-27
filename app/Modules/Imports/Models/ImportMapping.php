<?php

namespace App\Modules\Imports\Models;

use App\Modules\Core\Models\User;
use App\Modules\Imports\Database\Factories\ImportMappingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Mapeo de columnas guardado para una plantilla: `columns` es columna de origen del archivo → campo
 * de la plantilla. Cada layout de exportación del SII se mapea una vez. Se desactiva, no se borra.
 */
#[Fillable(['template', 'name', 'columns', 'created_by', 'is_active'])]
#[UseFactory(ImportMappingFactory::class)]
class ImportMapping extends Model
{
    /** @use HasFactory<ImportMappingFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['columns' => 'array', 'is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
