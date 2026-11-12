<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Database\Factories\TermFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Periodo escolar con el código del SII (`20271` Ene-Jun, `20272` verano, `20273` Ago-Dic). Solo uno es
 * el actual para capturar carga.
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
     * En qué modalidades se abrió (M4).
     *
     * @return HasMany<TermOpening, $this>
     */
    public function openings(): HasMany
    {
        return $this->hasMany(TermOpening::class);
    }

    /**
     * Las claves de las modalidades donde se abrió, ordenadas; pide `openings.modality` cargado (también con la
     * modalidad desactivada o borrada después) para no consultar por periodo.
     *
     * @return Attribute<list<string>, never>
     */
    protected function openedModalityCodes(): Attribute
    {
        return Attribute::get(fn (): array => $this->openings
            ->map(fn (TermOpening $opening): ?string => $opening->modality?->code)
            ->filter()
            ->sort()
            ->values()
            ->all());
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
