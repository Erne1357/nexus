<?php

namespace App\Modules\AcademicLoad\Listeners;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Events\ModalityDeactivating;
use App\Modules\Core\Models\TermOpening;

/**
 * Antes de desactivar una modalidad (M11, confirmado el 12 de noviembre de 2026) dice cuántas cargas sin publicar
 * tiene en cada periodo abierto en ella. Solo lee.
 */
final class ReportUnpublishedLoadsOnModalityDeactivating
{
    public function handle(ModalityDeactivating $event): void
    {
        AcademicLoad::query()
            ->where('modality_id', $event->modality->getKey())
            ->where('status', '!=', LoadStatus::Published)
            ->whereIn('term_id', TermOpening::query()->where('modality_id', $event->modality->getKey())->select('term_id'))
            ->selectRaw('term_id, count(*) as total')
            ->groupBy('term_id')
            ->get()
            ->each(fn (AcademicLoad $row) => $event->unpublished($row->term_id, (int) $row->total));
    }
}
