<?php

namespace App\Modules\AcademicLoad\Listeners;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Events\ModalityDeactivating;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use Carbon\CarbonImmutable;

/**
 * Antes de desactivar una modalidad (M11, confirmado el 12 de noviembre de 2026) dice cuántas cargas sin publicar
 * tiene en cada periodo abierto en ella. Solo cuentan los periodos vigentes y futuros, los que terminan hoy o después
 * en la zona del Tec (M13, confirmado el 12 de noviembre de 2026): un periodo ya terminado con cargas sin publicar no
 * la detiene. Solo lee.
 */
final class ReportUnpublishedLoadsOnModalityDeactivating
{
    public function handle(ModalityDeactivating $event): void
    {
        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();

        AcademicLoad::query()
            ->where('modality_id', $event->modality->getKey())
            ->where('status', '!=', LoadStatus::Published)
            ->whereIn('term_id', TermOpening::query()->where('modality_id', $event->modality->getKey())->select('term_id'))
            ->whereIn('term_id', Term::query()->whereDate('ends_on', '>=', $today)->select('id'))
            ->selectRaw('term_id, count(*) as total')
            ->groupBy('term_id')
            ->get()
            ->each(fn (AcademicLoad $row) => $event->unpublished($row->term_id, (int) $row->total));
    }
}
