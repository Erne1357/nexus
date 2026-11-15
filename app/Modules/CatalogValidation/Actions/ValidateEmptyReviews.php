<?php

namespace App\Modules\CatalogValidation\Actions;

use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Queries\CatalogContentsQuery;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Validarlos»: el jefe valida en un paso los catálogos abiertos de su departamento que no tienen registros activos
 * (CV7, D5, confirmado el 14 de noviembre de 2026): una revisión sin activos se valida, y no hay nada que revisar en
 * ella. Valida cada una con ValidateReview, así queda la misma foto, la misma bitácora y el mismo aviso que con Validar.
 * Quién puede lo decide CatalogReviewPolicy::validate, revisión por revisión, antes de llamarla.
 *
 * Todo o nada, en una transacción: si una ya tiene registros activos (alguien dio uno de alta mientras tanto) o ya está
 * validada, no valida ninguna y lo dice. Cuenta los activos con la revisión ya bloqueada. Devuelve cuántas validó.
 */
final class ValidateEmptyReviews
{
    public function __construct(
        private readonly ValidateReview $validateReview,
        private readonly CatalogContentsQuery $contents,
    ) {}

    /**
     * @param  iterable<CatalogReview>  $reviews
     *
     * @throws ValidationException
     */
    public function handle(iterable $reviews, User $actor): int
    {
        return DB::transaction(function () use ($reviews, $actor): int {
            $validated = 0;

            foreach ($reviews as $review) {
                $review->newQuery()->lockForUpdate()->findOrFail($review->getKey());

                if ($this->contents->progress($review)['total'] > 0) {
                    throw ValidationException::withMessages(['status' => "La revisión de {$review->heading} ya tiene registros activos: valídala desde su lista."]);
                }

                $this->validateReview->handle($review, $actor);
                $validated++;
            }

            return $validated;
        });
    }
}
