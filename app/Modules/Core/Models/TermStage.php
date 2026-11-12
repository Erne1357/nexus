<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Database\Factories\TermStageFactory;
use App\Modules\Core\Enums\Stage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ventana de fechas de una etapa del periodo (M4, confirmado el 12 de noviembre de 2026: el calendario es por
 * modalidad). Sin modalidad es la general y aplica a todas; la de una modalidad la reemplaza en ella.
 */
#[Fillable(['term_id', 'modality_id', 'stage', 'starts_on', 'ends_on'])]
#[UseFactory(TermStageFactory::class)]
class TermStage extends Model
{
    /** @use HasFactory<TermStageFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => Stage::class,
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<Term, $this>
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /**
     * @return BelongsTo<Modality, $this>
     */
    public function modality(): BelongsTo
    {
        return $this->belongsTo(Modality::class);
    }

    /**
     * Etapas que aplican a una modalidad: las suyas y las generales (`modality_id` nulo).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function appliesTo(Builder $query, Modality|string $modality): void
    {
        $modalityId = $modality instanceof Modality ? $modality->getKey() : $modality;

        $query->where(fn (Builder $query) => $query->whereNull('modality_id')->orWhere('modality_id', $modalityId));
    }
}
