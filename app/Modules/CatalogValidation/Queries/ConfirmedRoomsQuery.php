<?php

namespace App\Modules\CatalogValidation\Queries;

use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\Facilities\Models\Room;

/**
 * Qué salones de un departamento están confirmados en un periodo (tarea 1.53, SEL-01; confirmado el 26 de noviembre de
 * 2026): los que están en la foto de la revisión de salones del departamento ya validada (`snapshot`, que incluye los que
 * el departamento reclamó, CV16) y no tienen una observación abierta (sin resolver) en esa revisión. Una observación no
 * impide validar (CV4), pero el salón observado no se considera confirmado hasta que se resuelva (supuesto por confirmar).
 * Sin revisión de salones validada del departamento en el periodo no hay ningún salón confirmado.
 */
final class ConfirmedRoomsQuery
{
    /**
     * Si la revisión de salones del departamento está validada en el periodo.
     */
    public function isValidated(string $termId, string $departmentId): bool
    {
        return $this->review($termId, $departmentId) !== null;
    }

    /**
     * Los ids de los salones confirmados del departamento en el periodo.
     *
     * @return list<string>
     */
    public function ids(string $termId, string $departmentId): array
    {
        $review = $this->review($termId, $departmentId);

        if ($review === null) {
            return [];
        }

        $inSnapshot = array_column($review->snapshot ?? [], 'id');

        if ($inSnapshot === []) {
            return [];
        }

        $flagged = CatalogReviewFlag::query()
            ->where('review_id', $review->getKey())
            ->where('flaggable_type', (new Room)->getMorphClass())
            ->whereNull('resolved_at')
            ->pluck('flaggable_id')
            ->all();

        return array_values(array_diff($inSnapshot, $flagged));
    }

    private function review(string $termId, string $departmentId): ?CatalogReview
    {
        return CatalogReview::query()
            ->where('term_id', $termId)
            ->where('department_id', $departmentId)
            ->where('catalog', Catalog::Rooms)
            ->where('status', ReviewStatus::Validated)
            ->first();
    }
}
