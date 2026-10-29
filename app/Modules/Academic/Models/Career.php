<?php

namespace App\Modules\Academic\Models;

use App\Modules\Academic\Database\Factories\CareerFactory;
use App\Modules\Core\Models\Campus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Carrera, con el departamento que la coordina. `code` es la clave natural.
 */
#[Fillable(['code', 'name', 'department_id', 'is_active'])]
#[UseFactory(CareerFactory::class)]
class Career extends Model
{
    /** @use HasFactory<CareerFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Los campus donde se ofrece, con `pivot.is_active`; sin las filas de `career_campus` borradas.
     *
     * @return BelongsToMany<Campus, $this>
     */
    public function campuses(): BelongsToMany
    {
        return $this->belongsToMany(Campus::class, 'career_campus')
            ->withPivot('is_active')
            ->withTimestamps()
            ->wherePivotNull('deleted_at');
    }
}
