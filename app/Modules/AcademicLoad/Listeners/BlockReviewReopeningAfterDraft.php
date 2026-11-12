<?php

namespace App\Modules\AcademicLoad\Listeners;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\CatalogValidation\Events\CatalogReviewReopening;
use Illuminate\Support\Arr;

/**
 * Veta reabrir una revisión de catálogo validada si alguna carga del departamento en el periodo ya salió de
 * borrador en cualquier campus (CV5, confirmado el 2 de noviembre de 2026; reglas-de-negocio.md, "Validación de
 * catálogos"), también la de salones, que es una para todos los campus (decisión 6, confirmada el 12 de noviembre de
 * 2026). «La carga académica de SIS ya salió de borrador en C1 y C2.»
 *
 * Corre dentro de la transacción de ReopenReview, que ya bloqueó la revisión con FOR UPDATE. ProposeLoad lee
 * las revisiones validadas con FOR SHARE (CatalogsValidated, HC-14), así que las dos operaciones se
 * serializan: si la carga se propuso antes, aquí ya se ve fuera de borrador y se veta; si la revisión se
 * reabre antes, ProposeLoad la vuelve a leer ya en revisión y HC-14 la bloquea.
 */
final class BlockReviewReopeningAfterDraft
{
    public function handle(CatalogReviewReopening $event): void
    {
        $review = $event->review;

        $campuses = AcademicLoad::query()
            ->join('campuses', 'campuses.id', '=', 'academic_loads.campus_id')
            ->where('academic_loads.term_id', $review->term_id)
            ->where('academic_loads.department_id', $review->department_id)
            ->where('academic_loads.status', '!=', LoadStatus::Draft)
            ->orderBy('campuses.code')
            ->pluck('campuses.code')
            ->all();

        if ($campuses === []) {
            return;
        }

        $list = Arr::join($campuses, ', ', ' y ');

        $event->block("La carga académica de {$review->department->code} ya salió de borrador en {$list}.");
    }
}
