<?php

namespace App\Modules\CatalogValidation\Queries;

use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewRoomClaim;

/**
 * Qué salones reclamó una revisión de salones (CV16, confirmado el 20 de noviembre de 2026): los reclamos vigentes (los
 * no soltados) con quién y cuándo, para la insignia «Reclamado» y la acción «Soltar» de la lista.
 */
final class RoomClaimQuery
{
    /**
     * Los reclamos vigentes de la revisión sobre los salones dados, por id de salón. Sin ids, todos los de la revisión.
     *
     * @param  ?list<string>  $roomIds
     * @return array<string, array{by: ?string, at: string}>
     */
    public function activeOf(CatalogReview $review, ?array $roomIds = null): array
    {
        return CatalogReviewRoomClaim::query()->active()
            ->where('catalog_review_id', $review->getKey())
            ->when($roomIds !== null, fn ($query) => $query->whereIn('room_id', $roomIds))
            ->with('claimer:id,name')
            ->get()
            ->mapWithKeys(fn (CatalogReviewRoomClaim $claim): array => [$claim->room_id => ['by' => $claim->claimer?->name, 'at' => $claim->claimed_at->timezone(config('app.timezone'))->translatedFormat('j M Y, H:i')]])
            ->all();
    }
}
