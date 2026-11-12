<?php

namespace App\Modules\CatalogValidation\Actions;

use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Rules\CatalogReviewRules;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El jefe quita la marca de revisado de un registro de su revisión (CV7), mientras no está validada. Quién puede lo
 * decide CatalogReviewPolicy::mark antes de llamarla.
 *
 * En una transacción relee la revisión con bloqueo, exige que no esté validada y que el registro sea suyo, borra la
 * marca y deja la bitácora en la revisión. Sin marca no hace nada. No regresa la revisión a pendiente.
 */
final class UnmarkRecordReviewed
{
    public function __construct(private readonly CatalogReviewRules $rules) {}

    /**
     * @throws ValidationException
     */
    public function handle(CatalogReview $review, Model $record, User $actor): void
    {
        DB::transaction(function () use ($review, $record, $actor): void {
            $review->setRawAttributes($review->newQuery()->lockForUpdate()->findOrFail($review->getKey())->getAttributes(), sync: true);

            $this->rules->ensureOpen($review);
            $this->rules->ensureContains($review, $record);

            if ($review->marks()->where('record_id', $record->getKey())->delete() === 0) {
                return;
            }

            $code = $record->getAttribute($review->catalog === Catalog::Teachers ? 'rfc' : 'code');

            activity()
                ->performedOn($review)
                ->causedBy($actor)
                ->event('unreviewed')
                ->withProperties(['record' => $record->getKey()])
                ->log("Quitó la marca de revisado de {$code} en {$review->title}");
        });
    }
}
