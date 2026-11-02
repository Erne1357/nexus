<?php

namespace App\Modules\CatalogValidation\Rules;

use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Queries\CatalogContentsQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Lo que comparten FlagRecord y CorrectRecord (reglas-de-negocio.md, "Validación de catálogos"): el registro es
 * de la revisión, y la revisión no está validada (CV-S2, supuesto por confirmar; para cambiar algo de una
 * validada, primero se reabre). Las Actions las llaman con la revisión ya bloqueada.
 */
final class CatalogReviewRules
{
    public function __construct(private readonly CatalogContentsQuery $contents) {}

    /**
     * @throws ValidationException
     */
    public function ensureOpen(CatalogReview $review): void
    {
        if ($review->status === ReviewStatus::Validated) {
            throw ValidationException::withMessages(['status' => 'La revisión ya está validada: reábrela para corregir u observar.']);
        }
    }

    /**
     * @throws ValidationException
     */
    public function ensureContains(CatalogReview $review, Model $record): void
    {
        if (! $this->contents->contains($review, $record)) {
            throw ValidationException::withMessages(['record' => 'El registro no es de esta revisión.']);
        }
    }
}
