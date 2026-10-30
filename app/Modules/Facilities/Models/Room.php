<?php

namespace App\Modules\Facilities\Models;

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Campus;
use App\Modules\Facilities\Database\Factories\RoomFactory;
use App\Modules\Facilities\Enums\RoomType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Salón de un campus y de uno de sus edificios (el campus coincide por llave compuesta). `code` es la
 * clave natural dentro del campus; `department_id` es el departamento dueño, si lo hay; `features` es
 * una lista de características.
 */
#[Fillable(['campus_id', 'building_id', 'code', 'name', 'type', 'capacity', 'department_id', 'features', 'is_active'])]
#[UseFactory(RoomFactory::class)]
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'type' => RoomType::class,
            'capacity' => 'integer',
            'features' => 'array',
            'is_active' => 'boolean',
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
     * @return BelongsTo<Building, $this>
     */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /**
     * El departamento dueño.
     *
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return HasMany<RoomBlock, $this>
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(RoomBlock::class);
    }
}
