<?php

namespace App\Modules\CatalogValidation\Actions;

use App\Modules\CatalogValidation\Enums\ReviewFlag;
use App\Modules\CatalogValidation\Events\CatalogReviewFlagged;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\CatalogValidation\Rules\CatalogReviewRules;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Pone una observación del jefe sobre un registro de una revisión (reglas-de-negocio.md, "Validación de
 * catálogos"). No bloquea validar (CV4). Supuestos por confirmar (CV-S2): solo mientras la revisión no está
 * validada, «otra» lleva comentario y la capacidad incorrecta solo se observa en salones. Quién puede lo decide
 * CatalogReviewPolicy::flag antes de llamarla.
 *
 * En una transacción relee la revisión con bloqueo, exige que no esté validada y que el registro sea suyo
 * (CatalogReviewRules), guarda la observación con quién la puso, deja su bitácora, pasa la revisión a en
 * revisión (StartReview, CV-S4) y, después del commit, avisa con CatalogReviewFlagged.
 */
final class FlagRecord
{
    public function __construct(
        private readonly CatalogReviewRules $rules,
        private readonly StartReview $startReview,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(CatalogReview $review, Model $record, ReviewFlag $flag, ?string $comment, User $actor): CatalogReviewFlag
    {
        $comment = $comment === null || trim($comment) === '' ? null : trim($comment);

        if (! $flag->appliesTo($review->catalog)) {
            throw ValidationException::withMessages(['flag' => "«{$flag->label()}» no aplica a {$review->catalog->label()}."]);
        }

        if ($flag === ReviewFlag::Other && $comment === null) {
            throw ValidationException::withMessages(['comment' => 'Escribe la observación.']);
        }

        return DB::transaction(function () use ($review, $record, $flag, $comment, $actor): CatalogReviewFlag {
            $review->setRawAttributes($review->newQuery()->lockForUpdate()->findOrFail($review->getKey())->getAttributes(), sync: true);

            $this->rules->ensureOpen($review);
            $this->rules->ensureContains($review, $record);

            $created = $review->flags()->create([
                'flaggable_type' => $record->getMorphClass(),
                'flaggable_id' => $record->getKey(),
                'flag' => $flag,
                'comment' => $comment,
                'flagged_by' => $actor->getKey(),
            ]);

            activity()
                ->performedOn($created)
                ->causedBy($actor)
                ->event(ActivityEvent::Created)
                ->withChanges(['attributes' => [...$created->only(['review_id', 'flaggable_type', 'flaggable_id', 'comment', 'flagged_by']), 'flag' => $flag->value]])
                ->log("Puso la observación «{$flag->label()}» en {$review->title}");

            $this->startReview->handle($review, $actor);

            CatalogReviewFlagged::dispatch($created);

            return $created;
        });
    }
}
