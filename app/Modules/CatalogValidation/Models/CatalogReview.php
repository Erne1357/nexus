<?php

namespace App\Modules\CatalogValidation\Models;

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Database\Factories\CatalogReviewFactory;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Revisión de un catálogo de un departamento en un periodo. Sin campus si el catálogo es general (una vez
 * por periodo para todo el Tec); con el campus si son los salones (D2, confirmado el 1 de noviembre de 2026).
 */
#[Fillable(['term_id', 'campus_id', 'department_id', 'catalog', 'status', 'validated_by', 'validated_at', 'notes'])]
#[UseFactory(CatalogReviewFactory::class)]
class CatalogReview extends Model
{
    /** @use HasFactory<CatalogReviewFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'catalog' => Catalog::class,
            'status' => ReviewStatus::class,
            'validated_at' => 'immutable_datetime',
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
     * El campus de la revisión de salones; nulo en las generales.
     *
     * @return BelongsTo<Campus, $this>
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Quién la validó.
     *
     * @return BelongsTo<User, $this>
     */
    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }
}
