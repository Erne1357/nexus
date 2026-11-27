<?php

namespace App\Modules\CatalogValidation\Actions;

use App\Modules\CatalogValidation\Events\CatalogReviewFlagResolved;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marca como resuelta una observación, con quién y cuándo y, si se quiere, la nota de lo que se hizo (CV10, confirmado el
 * 14 de noviembre de 2026; hasta 1000 caracteres, recortada y vacía si viene en blanco), también si su revisión ya está
 * validada. Es idempotente: una ya resuelta se queda como está, sin otra bitácora ni aviso. Quién puede lo decide
 * CatalogReviewPolicy::resolveFlag sobre la revisión que se pasa antes de llamarla (por eso la observación debe ser de esa revisión) (CV-S1, supuesto por confirmar).
 *
 * En una transacción relee la observación con bloqueo, guarda, deja en la bitácora el valor anterior y el nuevo
 * y, después del commit, avisa con CatalogReviewFlagResolved, que lleva el aviso al jefe que la puso
 * (NotifyFlaggerOnFlagResolved).
 */
final class ResolveFlag
{
    /** El largo máximo de la nota. */
    public const int NOTE_MAX = 1000;

    /**
     * @throws ValidationException si la observación no es de la revisión que se pasa o la nota es muy larga
     */
    public function handle(CatalogReview $review, CatalogReviewFlag $flag, User $actor, ?string $note = null): CatalogReviewFlag
    {
        if ($flag->review_id !== $review->getKey()) {
            throw ValidationException::withMessages(['flag' => 'La observación no es de esta revisión.']);
        }

        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($note !== null && mb_strlen($note) > self::NOTE_MAX) {
            throw ValidationException::withMessages(['note' => 'La nota no puede pasar de '.self::NOTE_MAX.' caracteres.']);
        }

        return DB::transaction(function () use ($flag, $actor, $note): CatalogReviewFlag {
            $flag->setRawAttributes($flag->newQuery()->lockForUpdate()->findOrFail($flag->getKey())->getAttributes(), sync: true);

            if ($flag->resolved_at !== null) {
                return $flag;
            }

            $flag->update(['resolved_at' => now(), 'resolved_by' => $actor->getKey(), 'resolution_note' => $note]);

            activity()
                ->performedOn($flag)
                ->causedBy($actor)
                ->event('resolved')
                ->withChanges([
                    'attributes' => ['resolved_at' => $flag->resolved_at->toIso8601String(), 'resolved_by' => $actor->getKey(), 'resolution_note' => $note],
                    'old' => ['resolved_at' => null, 'resolved_by' => null, 'resolution_note' => null],
                ])
                ->log("Resolvió la observación «{$flag->flag->label()}» en {$flag->review->title}");

            CatalogReviewFlagResolved::dispatch($flag);

            return $flag;
        });
    }
}
