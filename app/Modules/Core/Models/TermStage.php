<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Enums\Stage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ventana de fechas de una etapa del periodo. Sin campus aplica a todos los campus.
 */
#[Fillable(['term_id', 'campus_id', 'stage', 'starts_on', 'ends_on'])]
class TermStage extends Model
{
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
     * @return BelongsTo<Campus, $this>
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /**
     * Etapas que aplican a un campus: las suyas y las de todos los campus (`campus_id` nulo).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function appliesTo(Builder $query, Campus|int $campus): void
    {
        $campusId = $campus instanceof Campus ? $campus->getKey() : $campus;

        $query->where(fn (Builder $query) => $query->whereNull('campus_id')->orWhere('campus_id', $campusId));
    }
}
