<?php

namespace App\Modules\CatalogValidation\Actions;

use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Events\CatalogReviewValidated;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Queries\CatalogContentsQuery;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Valida una revisión pendiente o en revisión (reglas-de-negocio.md, "Validación de catálogos"): guarda quién y
 * cuándo, y la foto de los registros activos del catálogo (CatalogContentsQuery::snapshot), contra la que el
 * periodo siguiente cuenta altas y bajas (CV3). Las observaciones abiertas no lo impiden (CV4). Con las
 * revisiones validadas, la carga del departamento puede salir de borrador (HC-14). Quién puede lo decide
 * CatalogReviewPolicy::validate antes de llamarla.
 *
 * En una transacción relee la revisión con bloqueo (así no se cruza con CorrectRecord ni con FlagRecord), deja
 * en la bitácora el estado anterior y el nuevo, con cuántos registros tiene la foto, y después del commit
 * avisa con CatalogReviewValidated. Una ya validada es ValidationException sobre `status`.
 */
final class ValidateReview
{
    public function __construct(private readonly CatalogContentsQuery $contents) {}

    /**
     * @throws ValidationException
     */
    public function handle(CatalogReview $review, User $actor): CatalogReview
    {
        return DB::transaction(function () use ($review, $actor): CatalogReview {
            $review->setRawAttributes($review->newQuery()->lockForUpdate()->findOrFail($review->getKey())->getAttributes(), sync: true);

            if ($review->status === ReviewStatus::Validated) {
                throw ValidationException::withMessages(['status' => 'La revisión ya está validada.']);
            }

            $old = ['status' => $review->status->value, 'validated_by' => $review->validated_by, 'validated_at' => $review->validated_at?->toIso8601String()];
            $snapshot = $this->contents->snapshot($review);

            $review->update([
                'status' => ReviewStatus::Validated,
                'validated_by' => $actor->getKey(),
                'validated_at' => now(),
                'snapshot' => $snapshot,
            ]);

            activity()
                ->performedOn($review)
                ->causedBy($actor)
                ->event('validated')
                ->withChanges([
                    'attributes' => ['status' => ReviewStatus::Validated->value, 'validated_by' => $actor->getKey(), 'validated_at' => $review->validated_at->toIso8601String()],
                    'old' => $old,
                ])
                ->withProperties(['records' => count($snapshot)])
                ->log("Validó {$review->title}");

            CatalogReviewValidated::dispatch($review);

            return $review;
        });
    }
}
