<?php

namespace App\Modules\AcademicLoad\Listeners;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\CatalogValidation\Events\CatalogReviewReopening;
use Illuminate\Support\Arr;

/**
 * Veta reabrir una revisión de catálogo validada si alguna carga del departamento en el periodo ya salió de
 * borrador en cualquier modalidad (CV5, confirmado el 2 de noviembre de 2026; reglas-de-negocio.md, "Validación de
 * catálogos"; la carga es por departamento y modalidad, M1), también la de salones, que es una para todos los campus
 * (decisión 6, confirmada el 12 de noviembre de 2026). «La carga académica de SIS ya salió de borrador en Presencial.»
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

        $modalities = AcademicLoad::query()
            ->join('modalities', 'modalities.id', '=', 'academic_loads.modality_id')
            ->where('academic_loads.term_id', $review->term_id)
            ->where('academic_loads.department_id', $review->department_id)
            ->where('academic_loads.status', '!=', LoadStatus::Draft)
            ->orderBy('modalities.code')
            ->pluck('modalities.name')
            ->all();

        if ($modalities === []) {
            return;
        }

        $list = Arr::join($modalities, ', ', ' y ');

        $event->block("La carga académica de {$review->department->code} ya salió de borrador en {$list}.");
    }
}
