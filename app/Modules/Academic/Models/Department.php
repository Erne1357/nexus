<?php

namespace App\Modules\Academic\Models;

use App\Modules\Academic\Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Departamento académico, global a la institución. `code` es la clave natural.
 */
#[Fillable(['code', 'name', 'is_active'])]
#[UseFactory(DepartmentFactory::class)]
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * Las carreras que coordina.
     *
     * @return HasMany<Career, $this>
     */
    public function careers(): HasMany
    {
        return $this->hasMany(Career::class);
    }

    /**
     * Quién lo dirige o lo dirigió, en cada campus.
     *
     * @return HasMany<DepartmentHead, $this>
     */
    public function heads(): HasMany
    {
        return $this->hasMany(DepartmentHead::class);
    }
}
