<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Database\Factories\OrgUnitFactory;
use App\Modules\Core\Enums\OrgUnitKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Unidad del organigrama: árbol por lista de adyacencia con una sola raíz (la Dirección).
 * Catálogo: soft deletes e `is_active`. ADR-0018, AZ-S1.
 */
#[Fillable(['parent_id', 'code', 'name', 'kind', 'sort', 'is_active'])]
#[UseFactory(OrgUnitFactory::class)]
class OrgUnit extends Model
{
    /** @use HasFactory<OrgUnitFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => OrgUnitKind::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<OrgUnit, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<OrgUnit, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
