<?php

namespace App\Modules\CatalogValidation\Actions;

use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Events\CatalogReviewStarted;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Pasa una revisión de pendiente a en revisión con la primera corrección u observación del jefe, no por solo
 * abrirla (CV-S4, supuesto por confirmar). En otro estado no hace nada. La llaman FlagRecord y CorrectRecord.
 *
 * En una transacción relee la revisión con bloqueo; si cambia, deja en la bitácora el estado anterior y el
 * nuevo y, después del commit, avisa con CatalogReviewStarted. Quién la llama ya pasó por la policy.
 */
final class StartReview
{
    public function handle(CatalogReview $review, User $actor): CatalogReview
    {
        return DB::transaction(function () use ($review, $actor): CatalogReview {
            $review->setRawAttributes($review->newQuery()->lockForUpdate()->findOrFail($review->getKey())->getAttributes(), sync: true);

            if ($review->status !== ReviewStatus::Pending) {
                return $review;
            }

            $review->update(['status' => ReviewStatus::InReview]);

            activity()
                ->performedOn($review)
                ->causedBy($actor)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => ['status' => ReviewStatus::InReview->value], 'old' => ['status' => ReviewStatus::Pending->value]])
                ->log("Empezó la revisión de {$review->title}");

            CatalogReviewStarted::dispatch($review);

            return $review;
        });
    }
}
