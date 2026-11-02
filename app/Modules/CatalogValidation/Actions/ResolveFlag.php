<?php

namespace App\Modules\CatalogValidation\Actions;

use App\Modules\CatalogValidation\Events\CatalogReviewFlagResolved;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marca como resuelta una observación, con quién y cuándo, también si su revisión ya está validada. Es
 * idempotente: una ya resuelta se queda como está, sin otra bitácora ni aviso. Quién puede lo decide
 * CatalogReviewPolicy::resolveFlag sobre la revisión que se pasa antes de llamarla (por eso la observación debe ser de esa revisión) (CV-S1, supuesto por confirmar).
 *
 * En una transacción relee la observación con bloqueo, guarda, deja en la bitácora el valor anterior y el nuevo
 * y, después del commit, avisa con CatalogReviewFlagResolved.
 */
final class ResolveFlag
{
    /**
     * @throws ValidationException si la observación no es de la revisión que se pasa
     */
    public function handle(CatalogReview $review, CatalogReviewFlag $flag, User $actor): CatalogReviewFlag
    {
        if ($flag->review_id !== $review->getKey()) {
            throw ValidationException::withMessages(['flag' => 'La observación no es de esta revisión.']);
        }

        return DB::transaction(function () use ($flag, $actor): CatalogReviewFlag {
            $flag->setRawAttributes($flag->newQuery()->lockForUpdate()->findOrFail($flag->getKey())->getAttributes(), sync: true);

            if ($flag->resolved_at !== null) {
                return $flag;
            }

            $flag->update(['resolved_at' => now(), 'resolved_by' => $actor->getKey()]);

            activity()
                ->performedOn($flag)
                ->causedBy($actor)
                ->event('resolved')
                ->withChanges([
                    'attributes' => ['resolved_at' => $flag->resolved_at->toIso8601String(), 'resolved_by' => $actor->getKey()],
                    'old' => ['resolved_at' => null, 'resolved_by' => null],
                ])
                ->log("Resolvió la observación «{$flag->flag->label()}» en {$flag->review->title}");

            CatalogReviewFlagResolved::dispatch($flag);

            return $flag;
        });
    }
}
