<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Database\Factories\PositionMailboxFactory;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Notifications\PositionRef;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * El buzón institucional de un puesto (CE-02): rol más alcance, con las columnas nulas como comodín, y su correo. Es
 * catálogo: se desactiva, nunca se borra. Lo escriben SavePositionMailbox y DeactivatePositionMailbox.
 */
#[Fillable(['role', 'org_unit_id', 'career_id', 'campus_id', 'modality_id', 'email', 'is_active'])]
#[UseFactory(PositionMailboxFactory::class)]
class PositionMailbox extends Model
{
    /** @use HasFactory<PositionMailboxFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Su puesto, con las columnas que el rol admite.
     */
    public function position(): PositionRef
    {
        return PositionRef::fromColumns($this->role, $this->only(PositionRef::COLUMNS));
    }
}
