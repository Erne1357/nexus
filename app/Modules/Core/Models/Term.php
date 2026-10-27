<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Database\Factories\TermFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Periodo escolar (`2027-1`, `2027-2`). Solo uno es el actual para capturar carga.
 */
#[Fillable(['code', 'name', 'starts_on', 'ends_on', 'is_current'])]
#[UseFactory(TermFactory::class)]
class Term extends Model
{
    /** @use HasFactory<TermFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'is_current' => 'boolean',
        ];
    }

    /**
     * @return HasMany<TermStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(TermStage::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function current(Builder $query): void
    {
        $query->where('is_current', true);
    }
}
