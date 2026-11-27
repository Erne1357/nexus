<?php

namespace App\Modules\CatalogValidation\Models;

use App\Modules\CatalogValidation\Database\Factories\CatalogReviewMarkFactory;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un registro de una revisión que el jefe ya revisó (CV7, confirmado el 12 de noviembre de 2026). `record_id` es el id
 * del registro del catálogo de la revisión. Solo cuenta mientras el registro siga en la revisión (CatalogContentsQuery);
 * reabrir la revisión la conserva (CV-S7, supuesto por confirmar).
 */
#[Fillable(['catalog_review_id', 'record_id', 'reviewed_by', 'reviewed_at'])]
#[UseFactory(CatalogReviewMarkFactory::class)]
class CatalogReviewMark extends Model
{
    /** @use HasFactory<CatalogReviewMarkFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<CatalogReview, $this>
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(CatalogReview::class, 'catalog_review_id');
    }

    /**
     * Quién lo marcó.
     *
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
