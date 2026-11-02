<?php

namespace App\Modules\CatalogValidation\Actions;

use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Events\CatalogReviewReopened;
use App\Modules\CatalogValidation\Events\CatalogReviewReopening;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reabre una revisión validada: queda en revisión, sin quién ni cuándo se validó, para que el jefe vuelva a
 * corregir (CV5). La foto se conserva hasta la siguiente validación, pero una reabierta ya no cuenta como la
 * validación anterior del periodo siguiente (CatalogReviewQuery::previousValidation solo lee validadas). Quién
 * puede lo decide CatalogReviewPolicy::reopen antes de llamarla.
 *
 * En una transacción relee la revisión con bloqueo y, antes de escribir, despacha CatalogReviewReopening: si
 * un módulo de la derecha la veta (AcademicLoad, porque una carga del departamento ya salió de borrador),
 * rechaza con sus motivos en `status` y no escribe nada. El bloqueo hace que el veto y ProposeLoad, que lee
 * las revisiones validadas con FOR SHARE, no se crucen. Si pasa, deja en la bitácora el estado anterior y el
 * nuevo y, después del commit, avisa con CatalogReviewReopened. Una que no está validada es
 * ValidationException sobre `status`.
 */
final class ReopenReview
{
    /**
     * @throws ValidationException
     */
    public function handle(CatalogReview $review, User $actor): CatalogReview
    {
        return DB::transaction(function () use ($review, $actor): CatalogReview {
            $review->setRawAttributes($review->newQuery()->lockForUpdate()->findOrFail($review->getKey())->getAttributes(), sync: true);

            if ($review->status !== ReviewStatus::Validated) {
                throw ValidationException::withMessages(['status' => 'Solo se reabre una revisión validada.']);
            }

            $event = new CatalogReviewReopening($review);
            event($event);

            if ($event->blocked()) {
                throw ValidationException::withMessages(['status' => $event->reasons()]);
            }

            $old = ['status' => $review->status->value, 'validated_by' => $review->validated_by, 'validated_at' => $review->validated_at?->toIso8601String()];

            $review->update(['status' => ReviewStatus::InReview, 'validated_by' => null, 'validated_at' => null]);

            activity()
                ->performedOn($review)
                ->causedBy($actor)
                ->event('reopened')
                ->withChanges(['attributes' => ['status' => ReviewStatus::InReview->value, 'validated_by' => null, 'validated_at' => null], 'old' => $old])
                ->log("Reabrió la revisión de {$review->title}");

            CatalogReviewReopened::dispatch($review);

            return $review;
        });
    }
}
