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
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Revisión de un catálogo de un departamento en un periodo, una vez por periodo para todo el Tec y sin campus, también
 * los salones de todos los campus (decisión 6, confirmada el 12 de noviembre de 2026; antes, D2, los salones eran por
 * campus). `campus_id` queda en la tabla, siempre nulo (CHECK `catalog_reviews_campus_check`).
 * `snapshot` es la foto de los registros activos al validarla: `list<array{id: string, code: string, name: ?string}>`,
 * nula hasta la primera validación.
 */
#[Fillable(['term_id', 'campus_id', 'department_id', 'catalog', 'status', 'validated_by', 'validated_at', 'notes', 'snapshot'])]
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
            'snapshot' => 'array',
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
     * Siempre nulo desde la decisión 6 (una revisión de salones para todos los campus).
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

    /**
     * Sus observaciones sobre registros.
     *
     * @return HasMany<CatalogReviewFlag, $this>
     */
    public function flags(): HasMany
    {
        return $this->hasMany(CatalogReviewFlag::class, 'review_id');
    }

    /**
     * Los registros que el jefe ya marcó como revisados (CV7). Solo cuentan los que siguen en la revisión.
     *
     * @return HasMany<CatalogReviewMark, $this>
     */
    public function marks(): HasMany
    {
        return $this->hasMany(CatalogReviewMark::class);
    }

    /**
     * «Materias de SIS», para la bitácora.
     *
     * @return Attribute<string, never>
     */
    protected function title(): Attribute
    {
        return Attribute::get(fn (): string => "{$this->catalog->label()} de {$this->department?->code}");
    }
}
