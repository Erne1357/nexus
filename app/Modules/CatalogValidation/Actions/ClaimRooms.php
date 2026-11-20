<?php

namespace App\Modules\CatalogValidation\Actions;

use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewRoomClaim;
use App\Modules\CatalogValidation\Rules\CatalogReviewRules;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Actions\ClaimRoomForDepartment;
use App\Modules\Facilities\Models\Room;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * «Reclamar salones sin dueño» (CV16, confirmado el 20 de noviembre de 2026): el jefe de departamento pasa a su
 * departamento los salones activos sin dueño, de cualquier campus, que elige en la revisión de Salones; entran a la
 * revisión como pendientes de revisar (CV7). Reclamar no es obligatorio para validar. Autoriza CatalogReviewPolicy::claimRooms
 * (corregir la revisión, solo el jefe de ESE departamento) y solo se reclama mientras la revisión no está validada
 * (CatalogReviewRules::ensureOpen): con una validada hay que reabrirla.
 *
 * En una transacción relee la revisión con bloqueo y cada salón, en orden de llave para que dos jefes no se crucen, con
 * ClaimRoomForDepartment (FOR UPDATE): el primero que reclama se lo queda, y el que ya tiene dueño, se inactivó o se
 * borró entre que se abrió el drawer y se confirmó se omite. Guarda un reclamo por salón (de ahí saldrá lo que puede
 * soltar, ReleaseRoomClaim) y una sola entrada de bitácora (`claimed`) con los salones reclamados. Devuelve los códigos
 * (campus · clave) de los reclamados y de los omitidos.
 */
final class ClaimRooms
{
    public function __construct(private readonly CatalogReviewRules $rules, private readonly ClaimRoomForDepartment $claimRoom) {}

    /**
     * @param  list<string>  $roomIds
     * @return array{claimed: list<string>, skipped: list<string>}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(CatalogReview $review, array $roomIds, User $actor): array
    {
        Gate::forUser($actor)->authorize('claimRooms', $review);

        $ids = collect($roomIds)->filter(fn (mixed $id): bool => is_string($id) && Str::isUuid($id))->unique()->sort()->values();

        if ($ids->isEmpty()) {
            throw ValidationException::withMessages(['claim' => 'Elige al menos un salón.']);
        }

        return DB::transaction(function () use ($review, $ids, $actor): array {
            $review->setRawAttributes($review->newQuery()->lockForUpdate()->findOrFail($review->getKey())->getAttributes(), sync: true);

            $this->rules->ensureOpen($review);

            if ($review->catalog !== Catalog::Rooms || $review->department_id === null) {
                throw ValidationException::withMessages(['claim' => 'Solo la revisión de salones de un departamento reclama salones.']);
            }

            $claimed = [];
            $skipped = [];
            $now = now();

            foreach ($ids as $id) {
                $room = Room::query()->withTrashed()->with('campus:id,code')->find($id);
                $code = $room === null ? $id : trim(($room->campus?->code ?? '').' · '.$room->code, ' ·');

                if ($room === null || ! $this->claimRoom->handle($id, $review->department_id)) {
                    $skipped[] = $code;

                    continue;
                }

                CatalogReviewRoomClaim::query()->create([
                    'catalog_review_id' => $review->getKey(),
                    'room_id' => $id,
                    'claimed_by' => $actor->getKey(),
                    'claimed_at' => $now,
                ]);
                $claimed[] = $code;
            }

            if ($claimed !== []) {
                activity()
                    ->performedOn($review)
                    ->causedBy($actor)
                    ->event('claimed')
                    ->withProperties(['rooms' => $claimed])
                    ->log(count($claimed) === 1 ? "Reclamó el salón {$claimed[0]} sin dueño para {$review->title}" : 'Reclamó '.count($claimed)." salones sin dueño para {$review->title}");
            }

            return ['claimed' => $claimed, 'skipped' => $skipped];
        });
    }
}
