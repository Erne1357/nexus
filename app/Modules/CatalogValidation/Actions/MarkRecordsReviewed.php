<?php

namespace App\Modules\CatalogValidation\Actions;

use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewMark;
use App\Modules\CatalogValidation\Rules\CatalogReviewRules;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El jefe marca como revisados uno o varios registros de su revisión (CV7, confirmado el 12 de noviembre de 2026): la
 * revisión solo se valida con todos revisados. Corregir un registro también lo marca (CorrectRecord). Quién puede lo
 * decide CatalogReviewPolicy::mark antes de llamarla.
 *
 * En una transacción relee la revisión con bloqueo (así no se cruza con ValidateReview), exige que no esté validada y que
 * todos los registros sean suyos (CatalogReviewRules: si uno no lo es, no marca ninguno), guarda la marca de los que no la
 * tenían con quién y cuándo (la de quien marcó primero se conserva), deja una entrada de bitácora en la revisión con los
 * ids nuevos y pasa la revisión a en revisión (StartReview, CV-S4). Marcar lo ya marcado no escribe. Da cuántos marcó.
 */
final class MarkRecordsReviewed
{
    public function __construct(
        private readonly CatalogReviewRules $rules,
        private readonly StartReview $startReview,
    ) {}

    /**
     * @param  list<string>  $recordIds
     *
     * @throws ValidationException
     */
    public function handle(CatalogReview $review, array $recordIds, User $actor): int
    {
        $ids = array_values(array_unique(array_map(strval(...), $recordIds)));

        if ($ids === []) {
            throw ValidationException::withMessages(['records' => 'Elige al menos un registro.']);
        }

        return DB::transaction(function () use ($review, $ids, $actor): int {
            $review->setRawAttributes($review->newQuery()->lockForUpdate()->findOrFail($review->getKey())->getAttributes(), sync: true);

            $this->rules->ensureOpen($review);
            $this->rules->ensureContainsAll($review, $ids);

            $new = array_values(array_diff($ids, $review->marks()->whereIn('record_id', $ids)->pluck('record_id')->all()));

            if ($new === []) {
                return 0;
            }

            $now = now();

            foreach (array_chunk($new, 1000) as $chunk) {
                CatalogReviewMark::query()->insert(array_map(fn (string $id): array => [
                    'catalog_review_id' => $review->getKey(),
                    'record_id' => $id,
                    'reviewed_by' => $actor->getKey(),
                    'reviewed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }

            activity()
                ->performedOn($review)
                ->causedBy($actor)
                ->event('reviewed')
                ->withProperties(['records' => $new])
                ->log(count($new) === 1 ? "Marcó 1 registro como revisado en {$review->title}" : 'Marcó '.count($new)." registros como revisados en {$review->title}");

            $this->startReview->handle($review, $actor);

            return count($new);
        });
    }
}
