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
 * Apertura de un periodo en un campus (D1, confirmado el 1 de noviembre de 2026): una por periodo y
 * campus, con quién y cuándo la hizo. La escribe OpenTerm.
 */
#[Fillable(['term_id', 'campus_id', 'opened_by', 'opened_at'])]
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
     * @return BelongsTo<Campus, $this>
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /**
     * Quién abrió el periodo en el campus.
     *
     * @return BelongsTo<User, $this>
     */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }
}
