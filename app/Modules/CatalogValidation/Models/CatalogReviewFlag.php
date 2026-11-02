<?php

namespace App\Modules\CatalogValidation\Models;

use App\Modules\CatalogValidation\Database\Factories\CatalogReviewFlagFactory;
use App\Modules\CatalogValidation\Enums\ReviewFlag;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Observación del jefe sobre un registro de una revisión de catálogo: una materia, una carrera, un plan, un
 * docente o un salón. No bloquea validar (CV4, confirmado el 2 de noviembre de 2026); se resuelve, no se borra.
 */
#[Fillable(['review_id', 'flaggable_type', 'flaggable_id', 'flag', 'comment', 'flagged_by', 'resolved_at', 'resolved_by'])]
#[UseFactory(CatalogReviewFlagFactory::class)]
class CatalogReviewFlag extends Model
{
    /** @use HasFactory<CatalogReviewFlagFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'flag' => ReviewFlag::class,
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<CatalogReview, $this>
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(CatalogReview::class, 'review_id');
    }

    /**
     * El registro observado, aunque después se haya borrado.
     *
     * @return MorphTo<Model, $this>
     */
    public function flaggable(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    /**
     * Quién la puso.
     *
     * @return BelongsTo<User, $this>
     */
    public function flagger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'flagged_by');
    }

    /**
     * Quién la resolvió.
     *
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
