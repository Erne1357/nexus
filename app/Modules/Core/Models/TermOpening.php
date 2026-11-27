<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Database\Factories\TermOpeningFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Apertura de un periodo en una modalidad (M4, confirmado el 12 de noviembre de 2026: Presencial se abre una vez para
 * C1 y C2): una por periodo y modalidad, con quién y cuándo la hizo. La escribe OpenTerm.
 */
#[Fillable(['term_id', 'modality_id', 'opened_by', 'opened_at'])]
#[UseFactory(TermOpeningFactory::class)]
class TermOpening extends Model
{
    /** @use HasFactory<TermOpeningFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['opened_at' => 'immutable_datetime'];
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
     * Quién abrió el periodo en la modalidad.
     *
     * @return BelongsTo<User, $this>
     */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }
}
