<?php

namespace App\Modules\CatalogValidation\Actions;

use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewRoomClaim;
use App\Modules\CatalogValidation\Rules\CatalogReviewRules;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Actions\ReleaseRoomFromDepartment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * «Soltar» un salón reclamado (CV16, confirmado el 20 de noviembre de 2026): regresa a sin dueño. Solo se sueltan los
 * que ESTA revisión reclamó (los que ya eran del departamento los suelta el admin) y solo mientras no esté validada:
 * si no, es ValidationException sobre `claim`. Autoriza CatalogReviewPolicy::releaseRoomClaim, como ClaimRooms.
 *
 * En una transacción relee la revisión con bloqueo, deja el salón sin dueño con ReleaseRoomFromDepartment, llena
 * `released_at` del reclamo, quita la marca de revisado del salón en la revisión (si se vuelve a reclamar, nace por
 * revisar) y deja su bitácora (`released`). Sus observaciones se quedan: la revisión solo cuenta las de sus registros.
 */
final class ReleaseRoomClaim
{
    public function __construct(private readonly CatalogReviewRules $rules, private readonly ReleaseRoomFromDepartment $releaseRoom) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(CatalogReview $review, string $roomId, User $actor): void
    {
        Gate::forUser($actor)->authorize('releaseRoomClaim', $review);

        if (! Str::isUuid($roomId)) {
            throw ValidationException::withMessages(['claim' => 'El salón no lo reclamó esta revisión.']);
        }

        DB::transaction(function () use ($review, $roomId, $actor): void {
            $review->setRawAttributes($review->newQuery()->lockForUpdate()->findOrFail($review->getKey())->getAttributes(), sync: true);

            $this->rules->ensureOpen($review);

            $claim = CatalogReviewRoomClaim::query()->active()
                ->where('catalog_review_id', $review->getKey())->where('room_id', $roomId)
                ->lockForUpdate()->with(['room' => fn ($room) => $room->withTrashed()->with('campus:id,code')])->first();

            if ($claim === null) {
                throw ValidationException::withMessages(['claim' => 'El salón no lo reclamó esta revisión.']);
            }

            // Si el admin ya cambió el dueño después del reclamo, el salón ya no es del departamento: no se suelta nada,
            // el reclamo queda como estaba y no se escribe bitácora.
            if (! $this->releaseRoom->handle($roomId, $review->department_id)) {
                throw ValidationException::withMessages(['claim' => 'El salón ya no es de tu departamento: no hay nada que soltar.']);
            }
            $claim->update(['released_at' => now()]);
            $review->marks()->where('record_id', $roomId)->delete();

            $code = trim(($claim->room->campus?->code ?? '').' · '.$claim->room->code, ' ·');

            activity()
                ->performedOn($review)
                ->causedBy($actor)
                ->event('released')
                ->withProperties(['rooms' => [$code]])
                ->log("Soltó el salón {$code} que había reclamado para {$review->title}");
        });
    }
}
